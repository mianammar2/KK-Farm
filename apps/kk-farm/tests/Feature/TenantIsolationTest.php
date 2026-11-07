<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalEvent;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RfidTag;
use App\Models\Shed;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D-037: one organization must never read or write another's data, whatever the code path.
 * Both organizations deliberately use the same breed name, shed code, farm number and tag code,
 * which also proves that uniqueness is per organization.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    /** @var array<string, string> animal ids by organization key */
    private array $animal = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Fixtures are written as the connection owner, then each test switches into the restricted role.
        foreach (['a' => 'Farm A', 'b' => 'Farm B'] as $key => $name) {
            $this->$key = Organization::create(['name' => $name, 'slug' => strtolower($key === 'a' ? 'farm-a' : 'farm-b')]);
            Tenant::set($this->$key->id);

            $breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
            $shed = Shed::create(['code' => 'SHED-1', 'name' => 'Shed 1']);
            $pen = Pen::create(['shed_id' => $shed->id, 'name' => 'Pen A', 'purpose' => 'Does']);
            $animal = Animal::create(['farm_number' => 'T-0001', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id]);
            RfidTag::create(['tag_code' => 'SIM-1']);
            WeightRecord::create(['animal_id' => $animal->id, 'measured_on' => now(), 'weight_kg' => 30, 'measured_by' => 'x']);
            AnimalEvent::create(['animal_id' => $animal->id, 'type' => 'registered', 'summary' => "Registered in $name", 'recorded_by' => 'x', 'occurred_at' => now()]);
            User::create(['name' => "Owner $name", 'email' => "owner-$key@test.local", 'password' => 'unused', 'role' => 'ceo']);

            $this->animal[$key] = $animal->id;
        }
        Tenant::clear();
    }

    private function tenantTables(): array
    {
        return array_column(DB::select(<<<'SQL'
            SELECT c.table_name FROM information_schema.columns c
            JOIN information_schema.tables t ON t.table_name = c.table_name AND t.table_schema = c.table_schema AND t.table_type = 'BASE TABLE'
            WHERE c.table_schema = 'public' AND c.column_name = 'org_id' AND c.table_name <> 'users'
            ORDER BY c.table_name
        SQL), 'table_name');
    }

    private function asTenant(Organization $org): void
    {
        Tenant::enter($org->id);
    }

    public function test_every_table_with_an_org_id_is_protected_by_forced_row_level_security(): void
    {
        $tables = $this->tenantTables();
        $this->assertGreaterThanOrEqual(18, count($tables));

        foreach ([...$tables, 'organizations'] as $table) {
            $row = DB::selectOne('SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = ?::regclass', [$table]);
            $this->assertTrue($row->on, "$table: row level security is not enabled");
            $this->assertTrue($row->forced, "$table: row level security is not forced");
            $this->assertGreaterThan(0, DB::selectOne('SELECT count(*) AS n FROM pg_policies WHERE tablename = ?', [$table])->n, "$table: no policy");
        }
    }

    public function test_the_application_role_cannot_bypass_row_level_security(): void
    {
        $role = DB::selectOne("SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = 'kkfarm_app'");

        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $this->assertSame('kkfarm_app', Tenant::role());
    }

    public function test_each_organization_sees_only_its_own_rows_in_every_table(): void
    {
        $expected = [];
        foreach ([$this->a, $this->b] as $org) {
            foreach ($this->tenantTables() as $table) {
                $expected[$org->id][$table] = DB::table($table)->where('org_id', $org->id)->count();
            }
        }
        $this->assertSame(1, $expected[$this->a->id]['animals']);

        foreach ([$this->a, $this->b] as $org) {
            $this->asTenant($org);
            foreach ($this->tenantTables() as $table) {
                $this->assertSame($expected[$org->id][$table], DB::table($table)->count(), "$table leaks rows for {$org->name}");
            }
            $this->assertSame(1, Organization::count());
            $this->assertSame($org->id, Organization::first()->id);
            Tenant::leave();
        }
    }

    public function test_a_tenant_cannot_read_update_or_delete_another_tenants_rows_by_id(): void
    {
        $this->asTenant($this->a);
        $theirs = $this->animal['b'];

        $this->assertSame(0, DB::table('animals')->where('id', $theirs)->count());
        $this->assertNull(Animal::find($theirs));
        $this->assertSame(0, DB::table('animals')->where('id', $theirs)->update(['name' => 'hijacked']));
        $this->assertSame(0, DB::table('animals')->where('id', $theirs)->delete());
        $this->assertSame(0, DB::table('weight_records')->where('animal_id', $theirs)->count());
        $this->assertSame(0, DB::table('animal_events')->where('animal_id', $theirs)->count());
        Tenant::leave();

        // Still intact for its owner.
        $this->asTenant($this->b);
        $this->assertNull(Animal::findOrFail($theirs)->name);
        Tenant::leave();
    }

    public function test_a_tenant_cannot_write_rows_for_another_tenant(): void
    {
        $this->asTenant($this->a);

        $this->assertRefused(fn () => Breed::create(['name' => 'Stolen', 'default_gestation_days' => 150, 'org_id' => $this->b->id]));
        $this->assertRefused(fn () => RfidTag::create(['tag_code' => 'SIM-2', 'org_id' => $this->b->id]));
        // Moving an own row into another organization is refused as well.
        $this->assertRefused(fn () => DB::table('breeds')->update(['org_id' => $this->b->id]));
        Tenant::leave();
    }

    public function test_without_a_bound_tenant_nothing_is_visible_or_writable(): void
    {
        Tenant::enter(null);

        foreach ($this->tenantTables() as $table) {
            $this->assertSame(0, DB::table($table)->count(), "$table is visible without a tenant");
        }
        $this->assertSame(0, Organization::count());
        $this->assertRefused(fn () => Breed::create(['name' => 'Orphan', 'default_gestation_days' => 150]));
        Tenant::leave();
    }

    public function test_values_that_are_unique_per_organization_may_repeat_across_organizations_but_not_within_one(): void
    {
        // Setup already created T-0001, SIM-1, 'Beetal' and SHED-1 in both organizations.
        $this->asTenant($this->a);
        $this->assertRefused(fn () => RfidTag::create(['tag_code' => 'SIM-1']));
        $this->assertRefused(fn () => Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]));
        $this->assertRefused(fn () => Shed::create(['code' => 'SHED-1', 'name' => 'Duplicate']));
        Tenant::leave();
    }

    public function test_a_request_cannot_open_another_tenants_goat_and_leaves_no_tenant_behind(): void
    {
        $ownerA = User::where('email', 'owner-a@test.local')->firstOrFail();

        $this->actingAs($ownerA)->get('/goats/'.$this->animal['a'])->assertOk();
        $this->assertNull(Tenant::current(), 'the tenant leaked past the end of the request');
        $this->assertSame('none', DB::selectOne("select current_setting('role') as v")->v, 'the restricted role leaked past the request');

        // Route-model binding must already be scoped, so another organization's goat does not exist for this user.
        $this->actingAs($ownerA)->get('/goats/'.$this->animal['b'])->assertNotFound();
    }

    /** Runs a write that must fail. A savepoint keeps the surrounding test transaction usable. */
    private function assertRefused(callable $write): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The database accepted a write it should have refused.');
    }
}

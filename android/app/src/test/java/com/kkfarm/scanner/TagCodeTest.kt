package com.kkfarm.scanner

import org.junit.Assert.assertEquals
import org.junit.Test

/** D-359: Tag IDs packed into 96-bit EPCs, the same bits the server decodes (GoatRegistration::normaliseCode). */
class TagCodeTest {
    @Test fun packsA13CharacterTagIdInto96Bits() {
        assertEquals("C6D58C9001C1042199900040", UhfReader.encode("MC-071126P-01"))
        assertEquals("C6D74D901101002199902140", UhfReader.encode("TD-141026P-25"))
    }

    @Test fun readsPackedAndAsciiTagIdsBack() {
        assertEquals("MC-071126P-01", UhfReader.decode("C6D58C9001C1042199900040"))
        assertEquals("MC-TEST-0001", UhfReader.decode("4D432D544553542D30303031"))
    }

    @Test fun leavesFactoryNumbersAlone() {
        assertEquals("E20000166515015616107331", UhfReader.decode("E20000166515015616107331"))
        assertEquals("C6D58C9001C1042199900041", UhfReader.decode("C6D58C9001C1042199900041"))
    }

    @Test fun longTagIdsStayAscii() {
        assertEquals("4B4B462D4253552D30312D5030303100", UhfReader.encode("KKF-BSU-01-P001"))
    }
}

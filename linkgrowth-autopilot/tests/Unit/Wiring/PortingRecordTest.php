<?php
/**
 * The porting record (tests/PORTING.md, DESIGN.md §1.3 step 5): an assertion retired inside a kept test names its
 * successor. Every engine document whose assertions a row retires must be named again in that row's replacement, so
 * the later package knows which document its test has to check.
 *
 * tests/PORTING.md names the engine and is left out of the source ZIP (§1.3 step 7); there the test is skipped.
 */

declare(strict_types=1);

namespace LinkGrowth\Autopilot\Tests\Unit\Wiring;

use LinkGrowth\Autopilot\Tests\Support\TestCase;

final class PortingRecordTest extends TestCase {

    private const FILE = __DIR__ . '/../../PORTING.md';

    public function testEveryRetiredDocumentAssertionNamesItsSuccessor(): void {
        if ( ! is_file( self::FILE ) ) {
            $this->markTestSkipped( 'tests/PORTING.md is not part of the source ZIP (DESIGN.md §1.3 step 7).' );
        }
        $text = (string) file_get_contents( self::FILE );
        $this->assertSame( 1, preg_match( '/^## Assertions retired inside kept tests$(.*?)^## /ms', $text, $m ), 'the retired-assertions section exists' );

        $rows = 0;
        foreach ( preg_split( '/\R/', $m[1] ) ?: [] as $line ) {
            if ( ! str_starts_with( $line, '| `' ) ) {
                continue; // header, separator, prose
            }
            $cells = array_map( 'trim', explode( '|', trim( $line, " |\t" ) ) );
            $this->assertCount( 3, $cells, 'test | retired assertions | replacement: ' . $line );
            [ $test, $retired, $replacement ] = $cells;
            ++$rows;
            $this->assertNotSame( '', $replacement, "{$test} names a replacement" );
            preg_match_all( '#docs/[A-Za-z0-9_-]+\.md#', $retired, $docs );
            foreach ( array_unique( $docs[0] ) as $doc ) {
                $this->assertStringContainsString( $doc, $replacement, "{$test}: the retired {$doc} assertions need a named successor" );
            }
        }
        $this->assertGreaterThan( 0, $rows, 'the section lists the retired assertions' );
    }
}

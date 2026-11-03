<?php
declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Support;

use PPC\LinkGrowth\Support\Crypto;
use PPC\LinkGrowth\Support\Secrets;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class CryptoSecretsTest extends TestCase {

    public function testRoundTripAndTamperDetection(): void {
        $c = new Crypto();
        $this->assertTrue( $c->hasStrongKeys() );
        $enc = $c->encrypt( 'sk-ant-secret-value' );
        $this->assertStringStartsWith( 'v1', $enc );
        $this->assertSame( 'sk-ant-secret-value', $c->decrypt( $enc ) );
        $this->assertSame( '', $c->encrypt( '' ) );
        $this->expectException( \RuntimeException::class );
        $c->decrypt( substr( $enc, 0, -4 ) . 'AAAA' );
    }

    public function testSecretsStoreMasksAndValidatesKeys(): void {
        $s = new Secrets( new Crypto() );
        $s->set( 'seranking_api_key', 'abcdef1234567890' );
        $this->assertTrue( $s->has( 'seranking_api_key' ) );
        $this->assertSame( 'abcdef1234567890', $s->get( 'seranking_api_key' ) );
        $this->assertStringEndsWith( '7890', $s->masked( 'seranking_api_key' ) );
        $this->assertStringNotContainsString( 'abcdef', $s->masked( 'seranking_api_key' ) );
        $stored = get_option( Secrets::OPTION );
        $this->assertStringNotContainsString( 'abcdef1234567890', json_encode( $stored ) );
        $s->set( 'not_a_real_key', 'x' );
        $this->assertFalse( $s->has( 'not_a_real_key' ) );
        $this->assertTrue( Secrets::isValidKey( 'partner_api_12' ) );
        $this->assertFalse( Secrets::isValidKey( 'partner_api_x' ) );
        $s->delete( 'seranking_api_key' );
        $this->assertFalse( $s->has( 'seranking_api_key' ) );
        $this->assertSame( 'configured (bot@proj.iam.gserviceaccount.com)', ( function () use ( $s ) {
            $s->set( 'google_service_account_json', json_encode( [ 'client_email' => 'bot@proj.iam.gserviceaccount.com', 'private_key' => 'x' ] ) );
            return $s->masked( 'google_service_account_json' );
        } )() );
    }
}

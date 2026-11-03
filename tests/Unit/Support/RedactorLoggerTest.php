<?php
declare(strict_types=1);

namespace PPC\LinkGrowth\Tests\Unit\Support;

use PPC\LinkGrowth\Repositories\Logs;
use PPC\LinkGrowth\Support\Logger;
use PPC\LinkGrowth\Support\Redactor;
use PPC\LinkGrowth\Support\Settings;
use PPC\LinkGrowth\Tests\Support\TestCase;

final class RedactorLoggerTest extends TestCase {

    public function testRedactorHidesSecretsAndEmails(): void {
        $out = Redactor::redact( [ 'api_key' => 'abc', 'nested' => [ 'Authorization' => 'Bearer xyz', 'note' => 'contact jane.doe@example.com or token sk-ant-abcdefghijklmnop' ] ] );
        $this->assertSame( '[redacted]', $out['api_key'] );
        $this->assertSame( '[redacted]', $out['nested']['Authorization'] );
        $this->assertStringContainsString( 'j***@example.com', $out['nested']['note'] );
        $this->assertStringNotContainsString( 'sk-ant-abcdefghijklmnop', $out['nested']['note'] );
        $this->assertStringContainsString( '[redacted private key]', Redactor::redactString( "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----" ) );
    }

    public function testLoggerRespectsLevelAndWritesRedactedRows(): void {
        $settings = new Settings();
        $settings->set( 'advanced.log_level', 'warning' );
        $logs   = new Logs();
        $logger = new Logger( $logs, $settings );
        $logger->info( 'test', 'ignored' );
        $logger->error( 'test', 'Failed for user bob@example.com', [ 'secret' => 'hidden', 'url' => 'https://a.test' ] );
        $rows = $logs->findBy( [], 'id', 'ASC', 10 );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'error', $rows[0]['level'] );
        $this->assertStringContainsString( 'b***@example.com', $rows[0]['message'] );
        $this->assertSame( '[redacted]', $rows[0]['context']['secret'] );
    }
}

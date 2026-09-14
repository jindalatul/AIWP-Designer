<?php
declare( strict_types = 1 );

use AIWP\Designer\Forms\FormHandler;
use PHPUnit\Framework\TestCase;

/**
 * A url field asks whether the address is well formed, not whether this server
 * can reach it.
 *
 * It used to call wp_http_validate_url(), which answers a different question:
 * may WordPress make a request to this URL. That refuses private and loopback
 * hosts and depends on what the server can resolve, so an ordinary customer
 * site came back invalid on a box with no DNS and a staging address came back
 * invalid everywhere. On a lead form that is not a warning — the person cannot
 * submit at all.
 */
final class FormUrlFieldTest extends TestCase {

	private function accepts( string $url ): bool {
		$method = new \ReflectionMethod( FormHandler::class, 'looks_like_a_web_address' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $url );
	}

	/** @return array<string,array{0:string}> */
	public static function real_addresses(): array {
		return array(
			'an ordinary company site' => array( 'https://my-shop.co.uk/store' ),
			'a domain that does not resolve here' => array( 'https://loftspace.test' ),
			'a staging host on an internal network' => array( 'https://staging.acme.internal' ),
			'a private ip' => array( 'http://192.168.1.10' ),
			'an internationalised domain' => array( 'https://xn--80ak6aa92e.com' ),
			'a url with a query' => array( 'https://example.com/shop?page=2' ),
		);
	}

	/** @dataProvider real_addresses */
	public function test_a_real_address_is_accepted( string $url ): void {
		$this->assertTrue( $this->accepts( $url ), $url );
	}

	/** @return array<string,array{0:string}> */
	public static function not_addresses(): array {
		return array(
			'words'          => array( 'not a url' ),
			'a script url'   => array( 'javascript:alert(1)' ),
			'a data url'     => array( 'data:text/html,<script>x</script>' ),
			'another scheme' => array( 'ftp://files.example.com' ),
			'no host'        => array( 'https://' ),
			'nothing'        => array( '' ),
		);
	}

	/** @dataProvider not_addresses */
	public function test_something_that_is_not_a_web_address_is_refused( string $url ): void {
		$this->assertFalse( $this->accepts( $url ), $url );
	}
}

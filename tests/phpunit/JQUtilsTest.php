<?php
declare( strict_types = 1 );

namespace Wikimedia\ZestJQ\Tests;

use LogicException;
use Wikimedia\ZestJQ\JQError;
use Wikimedia\ZestJQ\JQUtils;

/**
 * Unit tests for JQUtils static helpers.
 * @covers \Wikimedia\ZestJQ\JQUtils
 */
class JQUtilsTest extends \PHPUnit\Framework\TestCase {

	// -----------------------------------------------------------------------
	// toNumber
	// -----------------------------------------------------------------------

	public function testToNumberPassesThrough(): void {
		$this->assertSame( 42, JQUtils::toNumber( 42 ) );
		$this->assertSame( 3.14, JQUtils::toNumber( 3.14 ) );
		$this->assertSame( 0, JQUtils::toNumber( 0 ) );
	}

	public function testToNumberParsesIntString(): void {
		$this->assertSame( 42, JQUtils::toNumber( '42' ) );
	}

	public function testToNumberParsesFloatString(): void {
		$this->assertSame( 3.14, JQUtils::toNumber( '3.14' ) );
	}

	public function testToNumberParsesScientificNotation(): void {
		$this->assertSame( 1.0e5, JQUtils::toNumber( '1e5' ) );
	}

	public function testToNumberThrowsOnNull(): void {
		$this->expectException( JQError::class );
		JQUtils::toNumber( null );
	}

	public function testToNumberThrowsOnNonNumericString(): void {
		$this->expectException( JQError::class );
		JQUtils::toNumber( 'hello' );
	}

	public function testToNumberThrowsOnArray(): void {
		$this->expectException( JQError::class );
		JQUtils::toNumber( [] );
	}

	// -----------------------------------------------------------------------
	// toString
	// -----------------------------------------------------------------------

	public static function toStringProvider(): iterable {
		yield 'string passes through' => [ 'hello', 'hello' ];
		yield 'empty string passes through' => [ '', '' ];
		yield 'unicode string passes through' => [ 'héllo', 'héllo' ];
		yield 'integer' => [ 42, '42' ];
		yield 'float' => [ 3.14, '3.14' ];
		yield 'null' => [ null, 'null' ];
		yield 'true' => [ true, 'true' ];
		yield 'false' => [ false, 'false' ];
		yield 'array' => [ [ 1, 2, 3 ], '[1,2,3]' ];
		yield 'object' => [ (object)[ 'a' => 1 ], '{"a":1}' ];
		yield 'unicode in object' => [ (object)[ 'k' => 'café' ], '{"k":"café"}' ];
		yield 'slash unescaped' => [ [ 'a/b' ], '["a/b"]' ];
	}

	/** @dataProvider toStringProvider */
	public function testToString( mixed $val, string $expected ): void {
		$this->assertSame( $expected, JQUtils::toString( $val ) );
	}

	// -----------------------------------------------------------------------
	// jsonEncode
	// -----------------------------------------------------------------------

	public static function jsonEncodeProvider(): iterable {
		yield 'string gets double-quoted' => [ 'hello', '"hello"' ];
		yield 'empty string' => [ '', '""' ];
		yield 'integer' => [ 42, '42' ];
		yield 'float' => [ 1.5, '1.5' ];
		yield 'null' => [ null, 'null' ];
		yield 'true' => [ true, 'true' ];
		yield 'false' => [ false, 'false' ];
		yield 'array' => [ [ 1, 2 ], '[1,2]' ];
		yield 'object' => [ (object)[ 'x' => 1 ], '{"x":1}' ];
		yield 'unicode unescaped' => [ 'café', '"café"' ];
		yield 'slash unescaped' => [ 'a/b', '"a/b"' ];
	}

	/** @dataProvider jsonEncodeProvider */
	public function testJsonEncode( mixed $val, string $expected ): void {
		$this->assertSame( $expected, JQUtils::jsonEncode( $val ) );
	}

	// -----------------------------------------------------------------------
	// jsonDecode
	// -----------------------------------------------------------------------

	public function testJsonDecodeScalars(): void {
		$this->assertSame( 42, JQUtils::jsonDecode( '42' ) );
		$this->assertSame( 3.14, JQUtils::jsonDecode( '3.14' ) );
		$this->assertSame( 'hello', JQUtils::jsonDecode( '"hello"' ) );
		$this->assertNull( JQUtils::jsonDecode( 'null' ) );
		$this->assertTrue( JQUtils::jsonDecode( 'true' ) );
		$this->assertFalse( JQUtils::jsonDecode( 'false' ) );
	}

	public function testJsonDecodeObject(): void {
		$obj = JQUtils::jsonDecode( '{"a":1,"b":"two"}' );
		$this->assertInstanceOf( \stdClass::class, $obj );
		// @phan-suppress-next-line PhanUndeclaredProperty
		$this->assertSame( 1, $obj->a );
		// @phan-suppress-next-line PhanUndeclaredProperty
		$this->assertSame( 'two', $obj->b );
	}

	public function testJsonDecodeArray(): void {
		$this->assertSame( [ 1, 2, 3 ], JQUtils::jsonDecode( '[1,2,3]' ) );
	}

	public function testJsonDecodeStripsUtf8Bom(): void {
		$this->assertSame( 42, JQUtils::jsonDecode( "\xEF\xBB\xBF42" ) );
	}

	public function testJsonDecodeStripsUtf16LeBom(): void {
		$this->assertSame( 42, JQUtils::jsonDecode( "\xFF\xFE42" ) );
	}

	public function testJsonDecodeStripsUtf16BeBom(): void {
		$this->assertSame( 42, JQUtils::jsonDecode( "\xFE\xFF42" ) );
	}

	public function testJsonDecodeThrowsOnInvalid(): void {
		$this->expectException( JQError::class );
		JQUtils::jsonDecode( 'not json' );
	}

	// -----------------------------------------------------------------------
	// formatterFor — success cases
	// -----------------------------------------------------------------------

	public static function formatterForProvider(): iterable {
		// @text: same as toString
		yield '@text, string passthrough' => [ 'text', 'hello', 'hello' ];
		yield '@text, integer' => [ 'text', 42, '42' ];
		yield '@text, null' => [ 'text', null, 'null' ];

		// @json: always JSON-encodes, including strings
		yield '@json, string gets quoted' => [ 'json', 'hello', '"hello"' ];
		yield '@json, integer' => [ 'json', 42, '42' ];
		yield '@json, null' => [ 'json', null, 'null' ];
		yield '@json, array' => [ 'json', [ 1, 2 ], '[1,2]' ];

		// @html: HTML-escapes <, >, &, ", ' (as &apos; in XML1 mode)
		yield '@html, tags and ampersand' => [
			'html', '<b>Hello & World</b>', '&lt;b&gt;Hello &amp; World&lt;/b&gt;'
		];
		yield '@html, double quotes' => [ 'html', '"quoted"', '&quot;quoted&quot;' ];
		yield '@html, single quote' => [ 'html', "it's", 'it&apos;s' ];
		yield '@html, non-string value' => [ 'html', 42, '42' ];

		// @uri: percent-encodes via rawurlencode
		yield '@uri, space' => [ 'uri', 'hello world', 'hello%20world' ];
		yield '@uri, slash' => [ 'uri', 'a/b', 'a%2Fb' ];
		yield '@uri, plus' => [ 'uri', 'a+b', 'a%2Bb' ];
		yield '@uri, unicode' => [ 'uri', 'café', 'caf%C3%A9' ];

		// @urid: percent-decodes via rawurldecode; '+' is left as-is
		yield '@urid, space' => [ 'urid', 'hello%20world', 'hello world' ];
		yield '@urid, plus not decoded' => [ 'urid', 'a+b', 'a+b' ];
		yield '@urid, unicode' => [ 'urid', 'caf%C3%A9', 'café' ];

		// @base64
		yield '@base64, hello' => [ 'base64', 'hello', 'aGVsbG8=' ];
		yield '@base64, empty string' => [ 'base64', '', '' ];
		yield '@base64, non-string' => [ 'base64', 42, base64_encode( '42' ) ];

		// @base64d — strips whitespace before decoding
		yield '@base64d, simple' => [ 'base64d', 'aGVsbG8=', 'hello' ];
		yield '@base64d, with surrounding whitespace' => [ 'base64d', "  aGVsbG8=\n", 'hello' ];

		// @sh: wraps in single quotes, replacing ' with '\''
		yield '@sh, simple string' => [ 'sh', 'hello world', "'hello world'" ];
		yield '@sh, empty string' => [ 'sh', '', "''" ];
		yield '@sh, embedded single quote' => [ 'sh', "it's", "'it'\\''s'" ];
		yield '@sh, non-string' => [ 'sh', 42, "'42'" ];

		// @csv
		yield '@csv, mixed types' => [
			'csv', [ 1, 'hello', true, false, null ], '1,"hello",true,false,'
		];
		yield '@csv, float' => [ 'csv', [ 1.5 ], '1.5' ];
		yield '@csv, string with embedded double quote' => [
			'csv', [ '"hi"' ], '"""hi"""'
		];

		// @tsv
		yield '@tsv, mixed types' => [
			'tsv', [ 1, 'hello', true, false, null ], "1\thello\ttrue\tfalse\t"
		];
		yield '@tsv, tab in string gets escaped' => [ 'tsv', [ "a\tb" ], "a\\tb" ];
		yield '@tsv, backslash in string gets escaped' => [ 'tsv', [ 'a\\b' ], 'a\\\\b' ];
		yield '@tsv, newline in string gets escaped' => [ 'tsv', [ "a\nb" ], "a\\nb" ];
	}

	/** @dataProvider formatterForProvider */
	public function testFormatterFor( string $fmt, mixed $input, string $expected ): void {
		$fn = JQUtils::formatterFor( $fmt );
		$this->assertSame( $expected, $fn( $input ) );
	}

	// -----------------------------------------------------------------------
	// formatterFor — error cases
	// -----------------------------------------------------------------------

	public function testFormatterForUnknownThrows(): void {
		$this->expectException( LogicException::class );
		JQUtils::formatterFor( 'unknown' );
	}

	public function testFormatterForCsvRequiresList(): void {
		$fn = JQUtils::formatterFor( 'csv' );
		$this->expectException( JQError::class );
		$fn( 'not an array' );
	}

	public function testFormatterForTsvRequiresList(): void {
		$fn = JQUtils::formatterFor( 'tsv' );
		$this->expectException( JQError::class );
		$fn( 'not an array' );
	}

	public function testFormatterForCsvRejectsObject(): void {
		$fn = JQUtils::formatterFor( 'csv' );
		$this->expectException( JQError::class );
		$fn( (object)[ 'a' => 1 ] );
	}

}

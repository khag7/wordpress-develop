<?php

/**
 * @group pomo
 */
class PluralFormsTest extends WP_UnitTestCase {
	/**
	 * Legacy plural form function.
	 *
	 * @param int $nplurals
	 * @param string $expression
	 */
	protected static function make_plural_form_function($nplurals, $expression) {
		$expression = str_replace('n', '$n', $expression);
		$func_body = "
			\$index = (int)($expression);
			return (\$index < $nplurals)? \$index : $nplurals - 1;";
		return create_function('$n', $func_body);
	}

	/**
	 * Parenthesize plural expression.
	 *
	 * Legacy workaround for PHP's flipped precedence order for ternary.
	 *
	 * @param string $expression the expression without parentheses
	 * @return string the expression with parentheses added
	 */
	protected static function parenthesize_plural_expression($expression) {
		$expression .= ';';
		$res = '';
		$depth = 0;
		for ($i = 0; $i < strlen($expression); ++$i) {
			$char = $expression[$i];
			switch ($char) {
				case '?':
					$res .= ' ? (';
					$depth++;
					break;
				case ':':
					$res .= ') : (';
					break;
				case ';':
					$res .= str_repeat(')', $depth) . ';';
					$depth= 0;
					break;
				default:
					$res .= $char;
			}
		}
		return rtrim($res, ';');
	}

	public static function locales_provider() {
		if ( ! class_exists( 'GP_Locales' ) ) {
			$filename = download_url( 'https://raw.githubusercontent.com/GlotPress/GlotPress-WP/develop/locales/locales.php' );
			if ( is_wp_error( $filename ) ) {
				self::markTestSkipped( 'Unable to retrieve GP_Locales file' );
			}
			require_once $filename;			
		}

		$locales = GP_Locales::locales();
		$plural_expressions = array();
		foreach ( $locales as $slug => $locale ) {
			$plural_expression = $locale->plural_expression;
			if ( $plural_expression !== 'n != 1' ) {
				$plural_expressions[] = array( $slug, $locale->nplurals, $plural_expression );
			}
		}

		return $plural_expressions;
	}

	/**
	 * @dataProvider locales_provider
	 * @group external-http
	 */
	public function test_regression( $lang, $nplurals, $expression ) {
		$parenthesized = self::parenthesize_plural_expression( $expression );
		$old_style = self::make_plural_form_function( $nplurals, $parenthesized );
		$pluralForms = new Plural_Forms( $expression );

		$generated_old = array();
		$generated_new = array();

		foreach ( range( 0, 200 ) as $i ) {
			$generated_old[] = $old_style( $i );
			$generated_new[] = $pluralForms->get( $i );
		}

		$this->assertSame( $generated_old, $generated_new );
	}

	public static function simple_provider() {
		return array(
			array(
				// Simple equivalence.
				'n != 1',
				array(
					-1 => 1,
					0 => 1,
					1 => 0,
					2 => 1,
					5 => 1,
					10 => 1,
				),
			),
			array(
				// Ternary
				'n ? 1 : 2',
				array(
					-1 => 1,
					0 => 2,
					1 => 1,
					2 => 1,
				),
			),
			array(
				// Comparison
				'n > 1 ? 1 : 2',
				array(
					-2 => 2,
					-1 => 2,
					0 => 2,
					1 => 2,
					2 => 1,
					3 => 1,
				),
			),
			array(
				'n > 1 ? n > 2 ? 1 : 2 : 3',
				array(
					-2 => 3,
					-1 => 3,
					0 => 3,
					1 => 3,
					2 => 2,
					3 => 1,
					4 => 1,
				),
			),
		);
	}

	/**
	 * @dataProvider simple_provider
	 */
	public function test_simple( $expression, $expected ) {
		$pluralForms = new Plural_Forms( $expression );
		$actual = array();
		foreach ( array_keys( $expected ) as $num ) {
			$actual[ $num ] = $pluralForms->get( $num );
		}

		$this->assertSame( $expected, $actual );
	}

	public function data_exceptions() {
		return array(
			array(
				'n # 2',              // Invalid expression to parse
				'Unknown symbol "#"', // Expected exception message
				false,                // Whether to call the get() method or not
			),
			array(
				'n & 1',
				'Unknown operator "&"',
				false,
			),
			array(
				'((n)',
				'Mismatched parentheses',
				false,
			),
			array(
				'(n))',
				'Mismatched parentheses',
				false,
			),
			array(
				'n : 2',
				'Missing starting "?" ternary operator',
				false,
			),
			array(
				'n ? 1',
				'Unknown operator "?"',
				true,
			),
			array(
				'n n',
				'Too many values remaining on the stack',
				true,
			),
		);
	}

	/**
	 * @dataProvider data_exceptions
	 */
	public function test_exceptions( $expression, $expected_exception, $call_get ) {
		try {
			$pluralForms = new Plural_Forms( $expression );
			if( $call_get ) {
				$pluralForms->get( 1 );
			}
		} catch ( Exception $e ) {
			$this->assertEquals( $expected_exception, $e->getMessage() );
		}
	}

	public function test_cache() {
		$mock = $this->getMockBuilder( 'Plural_Forms' )
			->setMethods(array('execute'))
			->setConstructorArgs(array('n != 1'))
			->getMock();

		$mock->expects($this->once())
			->method('execute')
			->with($this->identicalTo(2))
			->will($this->returnValue(1));

		$first = $mock->get( 2 );
		$second = $mock->get( 2 );
		$this->assertEquals( $first, $second );
	}
}
<?php
/**
 * Minimal PDF 1.4 writer: text in the core Helvetica fonts (WinAnsi), lines and filled rectangles.
 *
 * No external library, so the plugin stays small and works on any host.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Quotes;

defined( 'ABSPATH' ) || exit;

/**
 * PDF writer. Coordinates are points from the top-left corner (converted internally).
 */
class Pdf {

	const W = 595.28;
	const H = 841.89;

	/**
	 * Helvetica widths (1/1000 em) for ASCII 32..126.
	 *
	 * @var int[]
	 */
	private static $regular = array( 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584 );

	/**
	 * Helvetica-Bold widths for ASCII 32..126.
	 *
	 * @var int[]
	 */
	private static $bold = array( 278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584 );

	/**
	 * Page content streams.
	 *
	 * @var string[]
	 */
	private $pages = array();

	/**
	 * Current page index.
	 *
	 * @var int
	 */
	private $page = -1;

	/**
	 * Document title.
	 *
	 * @var string
	 */
	private $title = '';

	/**
	 * Constructor.
	 *
	 * @param string $title Title metadata.
	 */
	public function __construct( $title = '' ) {
		$this->title = (string) $title;
	}

	/**
	 * Starts a new page.
	 */
	public function add_page() {
		$this->pages[] = '';
		$this->page    = count( $this->pages ) - 1;
	}

	/**
	 * Number of pages.
	 *
	 * @return int
	 */
	public function page_count() {
		return count( $this->pages );
	}

	/**
	 * Selects a page (for footers).
	 *
	 * @param int $index Index.
	 */
	public function set_page( $index ) {
		$this->page = max( 0, min( count( $this->pages ) - 1, (int) $index ) );
	}

	/**
	 * UTF-8 → Windows-1252 (characters outside it become "?").
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function encode( $text ) {
		$text = str_replace( array( "\r", "\t" ), array( '', ' ' ), (string) $text );
		$text = str_replace( array( "\u{2011}", "\u{00A0}", "\u{202F}" ), array( '-', ' ', ' ' ), $text );
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$prev = mb_substitute_character();
			mb_substitute_character( 0x3F );
			$out = mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' );
			mb_substitute_character( $prev );
			return (string) $out;
		}
		return (string) preg_replace( '/[^\x20-\x7E]/', '?', $text );
	}

	/**
	 * Whether a UTF-8 string survives the font encoding.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function encodable( $text ) {
		if ( ! function_exists( 'mb_convert_encoding' ) ) {
			return (bool) preg_match( '/^[\x20-\x7E]*$/', (string) $text );
		}
		$back = mb_convert_encoding( self::encode( $text ), 'UTF-8', 'Windows-1252' );
		return str_replace( array( "\r", "\t" ), array( '', ' ' ), (string) $text ) === $back;
	}

	/**
	 * Text width in points.
	 *
	 * @param string $text UTF-8 text.
	 * @param float  $size Font size.
	 * @param bool   $bold Bold.
	 * @return float
	 */
	public static function width( $text, $size, $bold = false ) {
		$enc    = self::encode( $text );
		$widths = $bold ? self::$bold : self::$regular;
		$sum    = 0;
		$len    = strlen( $enc );
		for ( $i = 0; $i < $len; $i++ ) {
			$c    = ord( $enc[ $i ] );
			$sum += $c >= 32 && $c <= 126 ? $widths[ $c - 32 ] : 556;
		}
		return $sum * $size / 1000;
	}

	/**
	 * Word-wraps text to a width.
	 *
	 * @param string $text  Text.
	 * @param float  $width Max width.
	 * @param float  $size  Size.
	 * @param bool   $bold  Bold.
	 * @return string[]
	 */
	public static function wrap( $text, $width, $size, $bold = false ) {
		$lines = array();
		foreach ( preg_split( '/\n/', (string) $text ) as $para ) {
			$words = preg_split( '/\s+/', trim( $para ) );
			$line  = '';
			foreach ( $words as $word ) {
				if ( '' === $word ) {
					continue;
				}
				$try = '' === $line ? $word : $line . ' ' . $word;
				if ( self::width( $try, $size, $bold ) <= $width ) {
					$line = $try;
					continue;
				}
				if ( '' !== $line ) {
					$lines[] = $line;
				}
				// Hard-break words longer than the width.
				while ( self::width( $word, $size, $bold ) > $width && mb_strlen( $word ) > 1 ) {
					$cut = mb_strlen( $word );
					while ( $cut > 1 && self::width( mb_substr( $word, 0, $cut ), $size, $bold ) > $width ) {
						--$cut;
					}
					$lines[] = mb_substr( $word, 0, $cut );
					$word    = mb_substr( $word, $cut );
				}
				$line = $word;
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Escapes a PDF string literal.
	 *
	 * @param string $text Encoded text.
	 * @return string
	 */
	private static function esc( $text ) {
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
	}

	/**
	 * Colour operator.
	 *
	 * @param string $hex  #rrggbb.
	 * @param bool   $fill Fill (else stroke).
	 * @return string
	 */
	private static function color( $hex, $fill = true ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			$hex = '000000';
		}
		$rgb = array_map( static fn( $p ) => round( hexdec( $p ) / 255, 3 ), str_split( $hex, 2 ) );
		return sprintf( '%.3F %.3F %.3F %s', $rgb[0], $rgb[1], $rgb[2], $fill ? 'rg' : 'RG' );
	}

	/**
	 * Draws text. $y is the baseline from the top.
	 *
	 * @param float  $x     X.
	 * @param float  $y     Baseline Y from the top.
	 * @param string $text  UTF-8 text.
	 * @param float  $size  Size.
	 * @param array  $opts  bold, color, align (left|right|center).
	 */
	public function text( $x, $y, $text, $size = 10, array $opts = array() ) {
		if ( $this->page < 0 ) {
			$this->add_page();
		}
		$bold  = ! empty( $opts['bold'] );
		$align = isset( $opts['align'] ) ? $opts['align'] : 'left';
		if ( 'right' === $align ) {
			$x -= self::width( $text, $size, $bold );
		} elseif ( 'center' === $align ) {
			$x -= self::width( $text, $size, $bold ) / 2;
		}
		$this->pages[ $this->page ] .= sprintf(
			"BT %s /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
			self::color( isset( $opts['color'] ) ? $opts['color'] : '#111827' ),
			$bold ? 'F2' : 'F1',
			$size,
			$x,
			self::H - $y,
			self::esc( self::encode( $text ) )
		);
	}

	/**
	 * Filled rectangle ($y = top edge from the top).
	 *
	 * @param float  $x     X.
	 * @param float  $y     Top.
	 * @param float  $w     Width.
	 * @param float  $h     Height.
	 * @param string $color Fill.
	 */
	public function rect( $x, $y, $w, $h, $color = '#f3f4f6' ) {
		if ( $this->page < 0 ) {
			$this->add_page();
		}
		$this->pages[ $this->page ] .= sprintf( "%s %.2F %.2F %.2F %.2F re f\n", self::color( $color ), $x, self::H - $y - $h, $w, $h );
	}

	/**
	 * Line.
	 *
	 * @param float  $x1    X1.
	 * @param float  $y1    Y1.
	 * @param float  $x2    X2.
	 * @param float  $y2    Y2.
	 * @param string $color Colour.
	 * @param float  $width Line width.
	 */
	public function line( $x1, $y1, $x2, $y2, $color = '#e5e7eb', $width = 0.75 ) {
		if ( $this->page < 0 ) {
			$this->add_page();
		}
		$this->pages[ $this->page ] .= sprintf( "%s %.2F w %.2F %.2F m %.2F %.2F l S\n", self::color( $color, false ), $width, $x1, self::H - $y1, $x2, self::H - $y2 );
	}

	/**
	 * Builds the file.
	 *
	 * @return string PDF bytes.
	 */
	public function output() {
		if ( ! $this->pages ) {
			$this->add_page();
		}
		$objects    = array();
		$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
		$kids       = array();
		$next       = 5;
		$page_objs  = array();
		foreach ( $this->pages as $content ) {
			$page_id     = $next++;
			$content_id  = $next++;
			$kids[]      = $page_id . ' 0 R';
			$page_objs[] = array( $page_id, $content_id, $content );
		}
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . count( $kids ) . ' >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		foreach ( $page_objs as $p ) {
			$objects[ $p[0] ] = sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $p[1] );
			$stream           = function_exists( 'gzcompress' ) ? gzcompress( $p[2] ) : $p[2];
			$filter           = function_exists( 'gzcompress' ) ? ' /Filter /FlateDecode' : '';
			$objects[ $p[1] ] = '<< /Length ' . strlen( $stream ) . $filter . " >>\nstream\n" . $stream . "\nendstream";
		}
		$info_id             = $next;
		$objects[ $info_id ] = '<< /Title (' . self::esc( self::encode( $this->title ) ) . ') /Producer (DEV3) /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>';
		ksort( $objects );

		$out     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $id => $body ) {
			$offsets[ $id ] = strlen( $out );
			$out           .= $id . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $out );
		$size = max( array_keys( $objects ) ) + 1;
		$out .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
		for ( $i = 1; $i < $size; $i++ ) {
			$out .= sprintf( "%010d 00000 n \n", isset( $offsets[ $i ] ) ? $offsets[ $i ] : 0 );
		}
		$out .= "trailer\n<< /Size " . $size . ' /Root 1 0 R /Info ' . $info_id . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $out;
	}
}

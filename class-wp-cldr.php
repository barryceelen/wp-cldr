<?php
/**
 * WP_CLDR class for fetching localization data from Unicode's Common Locale Data Repository
 *
 * The wp-cldr plugin is comprised of the WP_CLDR class, a subset of the reference JSON files from Unicode, and unit tests.
 *
 * @link https://github.com/Automattic/wp-cldr
 *
 * @package wp-cldr
 */

/**
 * Use CLDR localization data in WordPress.
 *
 * A class that fetches localized territory and language names, currency names/symbols,
 * and other localization info from the JSON distribution of Unicode's Common Locale
 * Data Repository (CLDR).
 *
 * Examples:
 *
 * The default locale is English:
 *
 * ```
 * $cldr = new WP_CLDR();
 * $territories_in_english = $cldr->get_territories_by_locale( 'en' );
 * ```
 *
 * You can override the default locale per-call by passing in a language slug in the second parameter:
 *
 * ```
 * $germany_in_arabic = $cldr->get_territory_name( 'DE' , 'ar_AR' );
 * ```
 *
 * Use a convenience parameter during instantiation to change the default locale
 *
 * ```
 * $cldr = new WP_CLDR( 'fr' );
 * $germany_in_french = $cldr->get_territory_name( 'DE' );
 * $us_dollar_in_french = $cldr->get_currency_name( 'USD' );
 * $canadian_french_in_french = $cldr->get_language_name( 'fr-ca' );
 * $canadian_french_in_english = $cldr->get_language_name( 'fr-ca' , 'en' );
 * $german_in_german = $cldr->get_language_name( 'de_DE' , 'de-DE' );
 * $bengali_in_japanese = $cldr->get_language_name( 'bn_BD' , 'ja_JP' );
 * $us_dollar_symbol_in_simplified_chinese = $cldr->get_currency_symbol( 'USD', 'zh' );
 * $africa_in_french = $cldr->get_territory_name( '002' );
 * ```
 *
 * Switch locales after the object has been created
 *
 * ```
 * $cldr->set_locale( 'en' );
 * $us_dollar_in_english = $cldr->get_currency_name( 'USD' );
 * ```
 *
 * Testing
 * The class included a set of PHPUnit tests. To run them, call `phpunit` from the plugin directory.
 *
 * @link http://cldr.unicode.org
 * @link https://github.com/unicode-cldr/cldr-json
 *
 * @autounit wp-cldr
 */
class WP_CLDR {
	/**
	 * The current locale.
	 *
	 * @var string
	 */
	private $locale = 'en';

	/**
	 * The in-memory array of localized values.
	 *
	 * @var array
	 */
	private $localized = array();

	/**
	 * Whether or not to use caching.
	 *
	 * @var bool
	 */
	private $use_cache = true;

	/**
	 * The cache group name to use for the WordPress object cache.
	 */
	const CACHE_GROUP = 'wp-cldr';

	/**
	 * The CLDR version, which the class uses to determine path to JSON files.
	 */
	const CLDR_VERSION = '28.0.2';

	/**
	 * Constructs a new instance of the class, including setting defaults for locale and caching.
	 *
	 * @param string $locale    Optional. A WordPress locale code.
	 * @param bool   $use_cache Optional. Whether to use caching (primarily used to suppress caching for unit testing).
	 */
	public function __construct( $locale = 'en', $use_cache = true ) {
		$this->use_cache = $use_cache;
		$this->set_locale( $locale );
	}

	/**
	 * Sets the locale.
	 *
	 * @param string $locale A WordPress locale code.
	 */
	public function set_locale( $locale ) {
		$this->locale = $locale;
		$this->initialize_locale_bucket( $locale );
	}

	/**
	 * Gets CLDR code for the equivalent WordPress locale code.
	 *
	 * @param string $wp_locale A WordPress locale code.
	 * @return string The equivalent CLDR locale code.
	 */
	public function get_cldr_locale( $wp_locale ) {

		// This array captures the WordPress locales that are significantly different from CLDR locales.
		$wp2cldr = array(
			'zh-cn' => 'zh-Hans',
			'zh-tw' => 'zh-Hant',
			'zh' => 'zh-Hans',
			'als' => 'gsw',
			'pt'	=> 'pt-PT',
			'pt-br'	=> 'pt', // In CLDR, the root `pt` locale is Brazilian Portuguese.
			'el-po'	=> 'el',
			'me' => 'sr-Latn-ME',
			'tl' => 'fil',
			'mya' => 'my',
			'tir' => 'ti',
			'bal' => 'ca',
			'bel' => 'be',
			'dzo' => 'dz',
			'fuc' => 'ff',
			'ido' => 'io',
			'ike' => 'iu',
			'haw-us' => 'haw',
			'kin' => 'rw',
			'lin' => 'ln',
			'me-me' => 'sr-Latn-ME',
			'mhr' => 'chm',
			'mri' => 'mi',
			'ory' => 'or',
			'ph' => 'fil',
			'roh' => 'rm',
			'srd' => 'sc',
			'tuk' => 'tk',
			'zh-hk' => 'zh-Hant',
			'zh-sg' => 'zh-Hans',
		);

		// Convert underscores to dashes and everything to lowercase.
		$cleaned_up_wp_locale = str_replace( '_', '-', $wp_locale );
		$cleaned_up_wp_locale = strtolower( $cleaned_up_wp_locale );

		// Check for an exact match in exceptions array.
		if ( isset( $wp2cldr[ $cleaned_up_wp_locale ] ) ) {
			return $wp2cldr[ $cleaned_up_wp_locale ];
		}

		// Capitalize country code and initial letter of script code to match CLDR JSON file names.
		$locale_components = explode( '-', $cleaned_up_wp_locale );
		if ( isset( $locale_components[1] ) && 2 === strlen( $locale_components[1] ) ) {
			$locale_components[1] = strtoupper( $locale_components[1] );
			$cleaned_up_wp_locale = implode( '-', $locale_components );
			return $cleaned_up_wp_locale;
		}
		if ( isset( $locale_components[1] ) && 2 < strlen( $locale_components[1] ) ) {
			$locale_components[1] = ucfirst( $locale_components[1] );
			$cleaned_up_wp_locale = implode( '-', $locale_components );
			return $cleaned_up_wp_locale;
		}

		return $cleaned_up_wp_locale;
	}

	/**
	 * Loads a CLDR JSON data file.
	 *
	 * @param string $cldr_locale The CLDR locale.
	 * @param string $bucket The CLDR data item.
	 * @return array  An array with the CLDR data from the file, or an empty array if no match with any CLDR data files.
	 */
	public static function get_cldr_json_file( $cldr_locale, $bucket ) {
		$base_path = __DIR__ . '/json/v' . WP_CLDR::CLDR_VERSION;

		switch ( $bucket ) {
			case 'territories':
			case 'languages':
				$relative_path = "cldr-localenames-modern/main/$cldr_locale";
				break;

			case 'currencies':
				$relative_path = "cldr-numbers-modern/main/$cldr_locale";
				break;

			default:
				$relative_path = 'cldr-core/supplemental';
				break;
		}

		$data_file_name = "$base_path/$relative_path/$bucket.json";

		if ( ! is_readable( $data_file_name ) ) {
			return array();
		}

		$json_raw = file_get_contents( $data_file_name );
		$json_decoded = json_decode( $json_raw, true );

		return $json_decoded;
	}

	/**
	 * Initializes a "bucket" of CLDR data items for a locale.
	 *
	 * @param string $locale Optional. The locale.
	 * @param string $bucket Optional. The CLDR data item.
	 */
	private function initialize_locale_bucket( $locale = 'en', $bucket = 'territories' ) {

		$cache_key = "cldr-$locale-$bucket";

		if ( $this->use_cache ) {
			$cached_data = wp_cache_get( $cache_key, WP_CLDR::CACHE_GROUP );
			if ( $cached_data ) {
				$this->localized[ $locale ][ $bucket ] = $cached_data;
			}
		}

		$cldr_locale = $this->get_cldr_locale( $locale );

		$json_file = self::get_cldr_json_file( $cldr_locale, $bucket );

		// If no language-country locale CLDR file, fall back to a language-only CLDR file.
		if ( empty( $json_file ) && 'supplemental' !== $bucket ) {
			$cldr_locale = strtok( $cldr_locale, '-_' );
			$json_file = self::get_cldr_json_file( $cldr_locale, $bucket );
		}

		// If no language CLDR file, fall back to English CLDR file.
		if ( empty( $json_file ) && 'supplemental' !== $bucket ) {
			$cldr_locale = 'en';
			$json_file = self::get_cldr_json_file( $cldr_locale, $bucket );
		}

		// Do some performance-enhancing pre-processing of data items, then put into cache
		// organized by WordPress locale.
		switch ( $bucket ) {
			case 'territories':
			case 'languages':
				$sorted_array = $json_file['main'][ $cldr_locale ]['localeDisplayNames'][ $bucket ];
				if ( function_exists( 'collator_create' ) ) {
					// Sort data according to locale collation rules.
					$coll = collator_create( $cldr_locale );
					collator_asort( $coll, $sorted_array, Collator::SORT_STRING );
				} else {
					asort( $sorted_array );
				}
				$this->localized[ $locale ][ $bucket ] = $sorted_array;
				break;

			case 'currencies':
			 	$this->localized[ $locale ][ $bucket ] = $json_file['main'][ $cldr_locale ]['numbers'][ $bucket ];
			 	break;

			default:
				$this->localized[ $locale ][ $bucket ] = $json_file;
				break;
		}

		if ( $this->use_cache ) {
			wp_cache_set( $cache_key, $this->localized[ $locale ][ $bucket ], WP_CLDR::CACHE_GROUP );
		}
	}

	/**
	 * Flushes the WordPress object cache for a single CLDR data item for a single locale.
	 *
	 * @param string $locale A WordPress locale code.
	 * @param string $bucket A CLDR data item.
	 */
	public function flush_wp_cache_for_locale_bucket( $locale, $bucket ) {
		$cache_key = "cldr-$locale-$bucket";
		return wp_cache_delete( $cache_key, WP_CLDR::CACHE_GROUP );
	}

	/**
	 * Clears the WordPress object cache for all CLDR data items across all locales.
	 */
	public function flush_all_wp_caches() {
		$this->localized = array();

		$locales = $this->get_languages_by_locale( 'en' );
		$supported_buckets = array( 'territories', 'currencies', 'languages', 'weekData', 'telephoneCodeData' );
		foreach ( array_keys( $locales ) as $locale ) {
			foreach ( $supported_buckets as $bucket ) {
				$this->flush_wp_cache_for_locale_bucket( $locale, $bucket );
			}
		}
	}

	/**
	 * Returns data for a single CLDR data item in a locale.
	 *
	 * @param string $locale A WordPress locale code.
	 * @param string $bucket A CLDR data item.
	 * @return array An associative array where keys are WordPress locales and values are CLDR data items
	 */
	private function get_locale_bucket( $locale, $bucket ) {
		if ( empty( $locale ) ) {
			$locale = $this->locale;
		}

		if ( isset( $this->localized[ $locale ][ $bucket ] ) ) {
			return $this->localized[ $locale ][ $bucket ];
		}

		// Maybe that bucket hasn't been initialized on this locale, let's try again.
		$this->initialize_locale_bucket( $locale, $bucket );

		if ( isset( $this->localized[ $locale ][ $bucket ] ) ) {
			return $this->localized[ $locale ][ $bucket ];
		}

		// Really not found.
		return array();
	}

	/**
	 * Gets the localized value for a single data item in a bucket of localized CLDR data.
	 *
	 * @param string $key    A key of a CLDR data item.
	 * @param string $locale A WordPress locale code.
	 * @param string $bucket A CLDR data item.
	 * @return string The localized CLDR data item in the selected locale.
	 */
	private function get_cldr_item( $key, $locale, $bucket ) {
		if ( ! is_string( $key ) || ! strlen( $key ) ) {
			return '';
		}

		$bucket_array = $this->get_locale_bucket( $locale, $bucket );

		if ( isset( $bucket_array[ $key ] ) ) {
			return (string) $bucket_array[ $key ];
		}

		return '';
	}

	/**
	 * Gets a localized country or region name.
	 *
	 * @link http://www.iso.org/iso/country_codes ISO 3166 country codes
	 * @link http://unstats.un.org/unsd/methods/m49/m49regin.htm UN M.49 region codes
	 *
	 * @param string $territory_code An ISO 3166-1 country code, or a UN M.49 region code.
	 * @param string $locale         Optional. A WordPress locale code.
	 * @return string The name of the territory in the provided locale.
	 */
	public function get_territory_name( $territory_code, $locale = '' ) {
		return $this->get_cldr_item( $territory_code, $locale, 'territories' );
	}

	/**
	 * Gets a localized currency symbol.
	 *
	 * @link http://www.iso.org/iso/currency_codes ISO 4217 currency codes
	 *
	 * @param string $currency_code An ISO 4217 currency code.
	 * @param string $locale        Optional. A WordPress locale code.
	 * @return string The symbol for the currency in the provided locale.
	 */
	public function get_currency_symbol( $currency_code, $locale = '' ) {
		$currencies_array = $this->get_locale_bucket( $locale, 'currencies' );
		if ( isset( $currencies_array[ $currency_code ]['symbol'] ) ) {
			return $currencies_array[ $currency_code ]['symbol'];
		}
		return '';
	}

	/**
	 * Gets a localized currency name.
	 *
	 * @link http://www.iso.org/iso/currency_codes ISO 4217 currency codes
	 *
	 * @param string $currency_code An ISO 4217 currency code.
	 * @param string $locale        Optional. A WordPress locale code.
	 * @return string The name of the currency in the provided locale.
	 */
	public function get_currency_name( $currency_code, $locale = '' ) {
		$currencies_array = $this->get_locale_bucket( $locale, 'currencies' );
		if ( isset( $currencies_array[ $currency_code ]['displayName'] ) ) {
			return $currencies_array[ $currency_code ]['displayName'];
		}
		return '';
	}

	/**
	 * Gets a localized language name.
	 *
	 * @link http://www.iso.org/iso/language_codes ISO 639 language codes
	 *
	 * @param string $language_code An ISO 639 language code.
	 * @param string $locale        Optional. A WordPress locale code.
	 * @return string The name of the language in the provided locale.
	 */
	public function get_language_name( $language_code, $locale = '' ) {
		$cldr_matched_language_code = $this->get_cldr_locale( $language_code );

		$language_name = $this->get_cldr_item( $cldr_matched_language_code, $locale, 'languages' );

		// If no match for locale (language-COUNTRY), try falling back to CLDR-matched language code only.
		if ( empty( $language_name ) ) {
			$language_name = $this->get_cldr_item( strtok( $language_code, '-_' ), $locale, 'languages' );
		}

		return $language_name;
	}

	/**
	 * Gets all country and region names in a locale.
	 *
	 * @link http://www.iso.org/iso/country_codes ISO 3166 country codes
	 * @link http://unstats.un.org/unsd/methods/m49/m49regin.htm UN M.49 region codes
	 *
	 * @param string $locale Optional. A WordPress locale code.
	 * @return array An associative array of ISO 3166-1 alpha-2 country codes and UN M.49 region codes, along with localized names, from CLDR
	 */
	public function get_territories_by_locale( $locale = '' ) {
		return $this->get_locale_bucket( $locale, 'territories' );
	}

	/**
	 * Gets all language names in a locale.
	 *
	 * @link http://www.iso.org/iso/language_codes ISO 639 language codes
	 *
	 * @param string $locale Optional. A WordPress locale code.
	 * @return array An associative array of ISO 639 codes and localized language names from CLDR
	 */
	public function get_languages_by_locale( $locale = '' ) {
		return $this->get_locale_bucket( $locale, 'languages' );
	}

	/**
	 * Gets telephone code for a country.
	 *
	 * @link http://unicode.org/reports/tr35/tr35-info.html#Telephone_Code_Data CLDR Telephone Code Data
	 * @link http://www.iso.org/iso/country_codes ISO 3166 country codes
	 *
	 * @param string $country_code A two-letter ISO 3166 country code.
	 * @return string The telephone code for the provided country.
	 */
	public function get_telephone_code( $country_code ) {
		$json_file = $this->get_locale_bucket( 'supplemental', 'telephoneCodeData' );
		if ( isset( $json_file['supplemental']['telephoneCodeData'][ $country_code ][0]['telephoneCountryCode'] ) ) {
			return $json_file['supplemental']['telephoneCodeData'][ $country_code ][0]['telephoneCountryCode'];
		}
		return '';
	}

	/**
	 * Gets the day which typically starts a calendar week in a country.
	 *
	 * @link http://unicode.org/reports/tr35/tr35-dates.html#Week_Data CLDR week data
	 * @link http://www.iso.org/iso/country_codes ISO 3166 country codes
	 *
	 * @param string $country_code A two-letter ISO 3166 country code.
	 * @return string The first three characters, in lowercase, of the English name for the day considered to be the start of the week.
	 */
	public function get_first_day_of_week( $country_code ) {
		$json_file = $this->get_locale_bucket( 'supplemental', 'weekData' );
		if ( isset( $json_file['supplemental']['weekData']['firstDay'][ $country_code ] ) ) {
			return $json_file['supplemental']['weekData']['firstDay'][ $country_code ];
		}
		return '';
	}

	/**
	 * Gets the currency used in each country worldwide.
	 *
	 * @link http://www.iso.org/iso/country_codes ISO 3166 country codes
	 * @link http://www.iso.org/iso/currency_codes ISO 4217 currency codes
	 *
	 * @return array An associative array of ISO 3166 country codes and the ISO 4217 code for the currency currently used in each country.
	 */
	public function get_currency_for_all_countries() {
		$json_file = $this->get_locale_bucket( 'supplemental', 'currencyData' );

		// This CLDR item has a history of all the currencies ever used in a country
		// so we need to loop through them to find one without a `_to` ending date
		// and without a `_tender` flag which are always false indicating
		// the currency wasn't legal tender.
		foreach ( $json_file['supplemental']['currencyData']['region'] as $country_code => $currencies ) {
			foreach ( $currencies as $currency_dates ) {
				if ( ! array_key_exists( '_to', current( $currency_dates ) ) && ! array_key_exists( '_tender', current( $currency_dates ) ) ) {
					$result[ $country_code ] = key( $currency_dates );
				}
			}
		}
		return $result;
	}

	/**
	 * Gets the currency currently used in a country.
	 *
	 * @link http://www.iso.org/iso/country_codes ISO 3166 country codes
	 * @link http://www.iso.org/iso/currency_codes ISO 4217 currency codes
	 *
	 * @param string $country_code A two-letter ISO 3166-1 country code.
	 * @return string The three-letter ISO 4217 code for the currency currently used in that country.
	 */
	public function get_currency_for_country( $country_code ) {
		$currency_for_all_countries = $this->get_currency_for_all_countries();
		if ( isset( $currency_for_all_countries[ $country_code ] ) ) {
			return $currency_for_all_countries[ $country_code ];
		}
		return '';
	}

	/**
	 * Gets the countries that use each currency in use today.
	 *
	 * @link http://www.iso.org/iso/currency_codes ISO 4217 currency codes
	 * @link http://www.iso.org/iso/country_codes ISO 3166 country codes
	 *
	 * @return array An associative array of ISO 4217 currency codes and then an array of the ISO 3166 codes for countries which currently each currency.
	 */
	public function get_countries_for_all_currencies() {
		$currency_for_all_countries = $this->get_currency_for_all_countries();
		foreach ( $currency_for_all_countries as $country_code => $currency_code ) {
			$result[ $currency_code ][] = $country_code;
		}
		return $result;
	}

	/**
	 * Gets the countries that use a particular currency.
	 *
	 * @link http://www.iso.org/iso/currency_codes ISO 4217 currency codes
	 * @link http://www.iso.org/iso/country_codes ISO 3166 country codes
	 *
	 * @param string $currency_code A three-letter ISO 4217 currency code.
	 * @return array The ISO 3166 codes for the countries which currently the currency.
	 */
	public function get_countries_for_currency( $currency_code ) {
		$countries_for_all_currencies = $this->get_countries_for_all_currencies();
		if ( isset( $countries_for_all_currencies[ $currency_code ] ) ) {
			return $countries_for_all_currencies[ $currency_code ];
		}
		return array();
	}

	/**
	 * Gets the countries contained by a region code.
	 *
	 * @link http://www.unicode.org/cldr/charts/latest/supplemental/territory_containment_un_m_49.html CLDR info page on territory containment
	 * @link http://www.iso.org/iso/country_codes ISO 3166 country codes
	 * @link http://unstats.un.org/unsd/methods/m49/m49regin.htm UN M.49 region codes
	 *
	 * @param string $region_code A UN M.49 region code or a two-letter ISO 3166-1 country code.
	 * @return array The countries included in that region, or the country if $region_code is a country.
	 */
	public function get_territories_contained( $region_code ) {

		// If $region_code is a country code, return it.
		if ( preg_match( '/^[A-Z]{2}$/', $region_code ) ) {
			return array( $region_code );
		}

		// If it's a region code, recursively find the contained country codes.
		if ( preg_match( '/^\d{3}$/', $region_code ) ) {
			$result = array();
			$json_file = $this->get_locale_bucket( 'supplemental', 'territoryContainment' );
			if ( isset( $json_file['supplemental']['territoryContainment'][ $region_code ]['_contains'] ) ) {
				foreach ( $json_file['supplemental']['territoryContainment'][ $region_code ]['_contains'] as $contained_region ) {
					$result = array_merge( $result, $this->get_territories_contained( $contained_region ) );
				}
				return $result;
			}
		}

		return array();
	}

	/**
	 * Gets the language spoken in a country, in descending order of use.
	 *
	 * @link http://www.iso.org/iso/country_codes ISO 3166 country codes
	 * @link http://www.unicode.org/cldr/charts/latest/supplemental/territory_language_information.html Detail on CLDR language information
	 *
	 * @param string $country_code A two-letter ISO 3166-1 country code.
	 * @return array An associative array with the key of a language code and the value of the percentage of population which speaks the language in that country.
	 */
	public function get_languages_spoken( $country_code ) {

		$result = array();
		$json_file = $this->get_locale_bucket( 'supplemental', 'territoryInfo' );

		if ( isset( $json_file['supplemental']['territoryInfo'][ $country_code ]['languagePopulation'] ) ) {
			foreach ( $json_file['supplemental']['territoryInfo'][ $country_code ]['languagePopulation'] as $language => $info ) {
				$result[ $language ] = $info['_populationPercent'];
			}
		}

		arsort( $result );

		return $result;
	}

	/**
	 * Gets the most widely spoken language spoken in a country.
	 *
	 * @link http://www.iso.org/iso/country_codes ISO 3166 country codes
	 * @link http://www.unicode.org/cldr/charts/latest/supplemental/territory_language_information.html Detail on CLDR language information
	 * @link http://www.iso.org/iso/language_codes ISO 639 language codes
	 *
	 * @param string $country_code A two-letter ISO 3166-1 country code.
	 * @return string The ISO 639 code for the language most widely spoken in the country.
	 */
	public function get_top_language_spoken( $country_code ) {
		$languages_spoken = $this->get_languages_spoken( $country_code );
		if ( ! empty( $languages_spoken ) ) {
			return key( $languages_spoken );
		}
		return '';
	}

	/**
	 * Gets GDP, population, and language information.
	 *
	 * @link http://www.iso.org/iso/country_codes ISO 3166 country codes
	 * @link http://www.unicode.org/cldr/charts/latest/supplemental/territory_language_information.html CLDR's territory information
	 *
	 * @param string $country_code Optional. A two-letter ISO 3166-1 country code.
	 * @return array CLDR's territory information.
	 */
	public function get_territory_info( $country_code ) {

		$result = array();
		$json_file = $this->get_locale_bucket( 'supplemental', 'territoryInfo' );

		if ( isset( $json_file['supplemental']['territoryInfo'] ) && '' === $country_code ) {
			return $json_file['supplemental']['territoryInfo'];
		}

		if ( isset( $json_file['supplemental']['territoryInfo'][ $country_code ] ) ) {
			return $json_file['supplemental']['territoryInfo'][ $country_code ];
		}

		return array();
	}
}
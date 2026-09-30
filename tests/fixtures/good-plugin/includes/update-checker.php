<?php
defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$prolific_puc = PucFactory::buildUpdateChecker(
	'https://api.prolificdigital.io/api/update?plugin=good-plugin',
	GOOD_PLUGIN_PLUGIN_FILE,
	'good-plugin'
);
$prolific_puc->addQueryArgFilter(
	static function ( array $args ): array {
		$key = defined( 'PROLIFIC_LICENSE_KEY_GOOD_PLUGIN' ) ? constant( 'PROLIFIC_LICENSE_KEY_GOOD_PLUGIN' ) : '';
		$key = (string) apply_filters( 'prolific_license_key', $key, 'good-plugin' );
		if ( '' !== $key ) {
			$args['license_key'] = $key;
		}
		return $args;
	}
);

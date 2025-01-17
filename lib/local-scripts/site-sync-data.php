<?php

// Replaces dashes in keys with underscores
foreach($args as $index => $arg) {
	$split = strpos($arg, "=");
	if ( $split ) {
		$key = str_replace('-', '_', substr( $arg , 0, $split ) );
		$value = substr( $arg , $split, strlen( $arg ) );

		// Removes unnecessary bash quotes
		$value = trim( $value,'"' ); 				// Remove last quote 
		$value = str_replace( '="', '=', $value );  // Remove quote right after equals

		$args[$index] = $key.$value;
	} else {
		$args[$index] = str_replace('-', '_', $arg);
	}

}

// Converts --arguments into $arguments
parse_str( implode( '&', $args ) );

// Fetch site details
$site_details   = json_decode( shell_exec( "captaincore site get {$site}-{$environment} --captain_id=$captain_id" ) );
$response       = shell_exec( "captaincore ssh {$site}-{$environment} --script=fetch-site-data --captain_id=$captain_id" );
$responses      = explode( "\n", $response );
$environment_id = ( new CaptainCore\Site( $site_details->site_id ) )->fetch_environment_id( $environment );
$valid          = true;

$environment_update = [
    "environment_id"    => $environment_id,
    "plugins"           => $responses[0],
    "themes"            => $responses[1],
    "core"              => $responses[2],
    "home_url"          => $responses[3],
    "users"             => $responses[4],
    "database_name"     => $responses[5],
    "database_username" => $responses[6],
    "database_password" => $responses[7],
    "subsite_count"     => $responses[8],
    "token"             => $responses[9],
    "updated_at"        => date("Y-m-d H:i:s"),
];

$plugins = json_decode( $responses[0] );
if (json_last_error() !== JSON_ERROR_NONE) {
   $valid = false;
}

$themes = json_decode( $responses[1] );
if (json_last_error() !== JSON_ERROR_NONE) {
   $valid = false;
}

if ( ! $valid ) {
    echo "Reponse not valid";
    return;
}

$json        = "{$_SERVER['HOME']}/.captaincore-cli/config.json";
$config_data = json_decode ( file_get_contents( $json ) );
$system      = $config_data[0]->system;

foreach($config_data as $config) {
	if ( isset( $config->captain_id ) and $config->captain_id == $captain_id ) {
		$configuration = $config;
		break;
	}
}

// Update current environment with new data.
( new CaptainCore\Environments )->update( $environment_update, [ "environment_id" => $environment_id ] );

// Prepare request to API
$request = [
    'method'  => 'POST',
    'headers' => [ 'Content-Type' => 'application/json' ],
    'body'    => json_encode( [ 
        "command" => "sync-data",
        "site_id" => $site_details->site_id,
        "token"   => $configuration->keys->token,
        "data"    => $environment_update,
    ] ),
];

if ( $system->captaincore_dev ) {
    $request['sslverify'] = false;
}

// Post to CaptainCore API
$response = wp_remote_post( $configuration->vars->captaincore_api, $request );
echo $response['body'];
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

$lookup = ( new CaptainCore\Sites )->where( [ "site" => $site ] );

// Error if site not found
if ( count( $lookup ) == 0 ) {
	echo "Error: Site '$site' not found.";
	return;
}

$site           = ( new CaptainCore\Sites )->get( $lookup[0]->site_id );
$environment_id = ( new CaptainCore\Site( $site->site_id ) )->fetch_environment_id( $environment );

foreach( [ "once" ] as $run ) {
    if ( $updates_exclude_themes != "" && $updates_exclude_plugins != "" ) {
        $response = shell_exec( "captaincore ssh {$site->site}-{$environment} --script=update --exclude_plugins=$updates_exclude_plugins --exclude_themes=$updates_exclude_themes --all --format=json --provider={$site->provider} --captain_id=$captain_id" );
        continue;
    }
    if ( $updates_exclude_themes != "" ) {
        $response = shell_exec( "captaincore ssh {$site->site}-{$environment} --script=update --exclude_themes=$updates_exclude_themes --all --format=json --provider={$site->provider} --captain_id=$captain_id" );
        continue;
    }
    if ( $updates_exclude_plugins != "" ) {
        $response = shell_exec( "captaincore ssh {$site->site}-{$environment} --script=update --exclude_plugins=$updates_exclude_plugins --all --format=json --provider={$site->provider} --captain_id=$captain_id" );
        continue;
    }
    $response = shell_exec( "captaincore ssh {$site->site}-{$environment} --script=update --all --format=json --provider={$site->provider} --captain_id=$captain_id" );
}

// Loads CLI configs
$json = "{$_SERVER['HOME']}/.captaincore-cli/config.json";

if ( ! file_exists( $json ) ) {
	echo "Error: Configuration file not found.";
	return;
}

$config_data = json_decode ( file_get_contents( $json ) );
$system      = $config_data[0]->system;
$path        = $system->path;
$new_logs    = [];

foreach($config_data as $config) {
	if ( isset( $config->captain_id ) and $config->captain_id == $captain_id ) {
		$configuration = $config;
		break;
	}
}

if ( $system->captaincore_fleet == "true" ) {
	$path = "{$path}/{$captain_id}";
}

// Define log file format
$logs_path = "$path/{$site->site}_{$site->site_id}/{$environment}/updates";

$responses = explode( "\n", $response );
foreach ( $responses as $key => $item ) {
    $time_now_file_name = date("Y-m-d-His");
    $time_now = date("Y-m-d H:i:s");
    $data     = json_decode( $item );
    // If JSON data not found, skip line
    if ( ! is_array( $data ) ) {
        continue;
    }
    if ( $key == "0" ) {
        $type = "theme";
    }
    if ( $key == "1" ) {
        $type = "plugin";
    }

    // Write to database
    $update_log_add = [
        "created_at"     => $time_now,
        "site_id"        => $site->site_id,
        "environment_id" => $environment_id,
        "update_type"    => $type,
        "update_log"     => json_encode( $data ),
    ];
    
    // Update current environment with new data.
    $log_id     = ( new CaptainCore\UpdateLogs )->insert( $update_log_add );

    // Append to output
    $new_logs[] = ( new CaptainCore\UpdateLogs )->get( $log_id );
    
    // Output to log file
    file_put_contents( "${logs_path}/{$time_now_file_name}-{$type}s.json", json_encode( $data ), JSON_PRETTY_PRINT );
}

foreach( $new_logs as $new_log ) {

    // Prepare request to API
    $request = [
        'method'  => 'POST',
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => json_encode( [ 
            "command" => "update-log-add",
            "site_id" => $site->site_id,
            "token"   => $configuration->keys->token,
            "data"    => $new_log,
        ] ),
    ];

    if ( $system->captaincore_dev ) {
        $request['sslverify'] = false;
    }

    // Post to CaptainCore API
    $response = wp_remote_post( $configuration->vars->captaincore_api, $request );
    echo $response['body'];

}
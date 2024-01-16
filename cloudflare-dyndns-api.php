<?php

//
// Settings
//
const USE_CACHE = TRUE; // Whether the script should write the current IP to a cache file and only update Cloudflare when it changes
const REQUIRE_AUTH = TRUE; // Whether username and password is checked before running the script
const TURN_ON_LOGGING = TRUE; // Whether this script should write to a log file
const AUTH_USERNAME = 'sunshine'; // Only used if REQUIRE_AUTH is TRUE
const AUTH_PASSWORD = 'abc123'; // Choose a strong password if you host this script on a public server.


//
// Get variables from query string
// If you're using the script for one domain only, you can fill "" with default values and omit the fields in requests to this API.
//
define("USERNAME", $_GET["user"] ?? "");
define("PASSWORD", $_GET["pass"] ?? "");
define("IPV4_ADDRESS", $_GET["ipv4"] ?? "");
define("IPV6_ADDRESS", $_GET["ipv6"] ?? "");
define("CLOUDFLARE_DOMAIN", $_GET["domain"] ?? "");
define("CLOUDFLARE_API_KEY", $_GET["cfapikey"] ?? "");
define("CLOUDFLARE_EMAIL", $_GET["cfemail"] ?? "");
define("CLOUDFLARE_RECORD_NAME", $_GET["cfrecordname"] ?? "");


//
// Thank god they finally added enums to PHP
//
enum IPVersion {
    case v4;
    case v6;

    public function type(): string {
        return match($this)
        {
            self::v4 => 'A',
            self::v6 => 'AAAA',
        };
    }

    public function name(): string {
        return match($this)
        {
            self::v4 => 'IPv4',
            self::v6 => 'IPv6',
        };
    }
}


//
// Log to file
//
function write_log(string $message) : void {
    if(!TURN_ON_LOGGING) return;
    $log_file_name =
        file_put_contents(CLOUDFLARE_RECORD_NAME . "." . CLOUDFLARE_DOMAIN . ".log", date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}


//
// Compare IP to cached IP
//
function ip_is_equal_to_cached_ip(string $ip, IPVersion $ip_version) : bool {
    if(!USE_CACHE) return false;

    $cache_file_name = CLOUDFLARE_RECORD_NAME . "." . CLOUDFLARE_DOMAIN . "." . $ip_version->name() . ".cache";
    if (!file_exists($cache_file_name)) {
        file_put_contents($cache_file_name, "");
    }

    return ($ip === file_get_contents($cache_file_name));
}


//
// Update Cache with new IP
//
function update_cache(string $ip_address, IPVersion $ip_version) : void {
    if(!USE_CACHE) return;

    $cache_file_name = CLOUDFLARE_RECORD_NAME . "." . CLOUDFLARE_DOMAIN . "." . $ip_version->name() . ".cache";
    file_put_contents($cache_file_name, $ip_address);
}


//
// Get Cloudflare Zone ID
//
function cloudflare_get_zone_id() : string {
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones" . '?name=' . CLOUDFLARE_DOMAIN);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'X-Auth-Email: ' . CLOUDFLARE_EMAIL,
        'X-Auth-Key: ' . CLOUDFLARE_API_KEY,
        'Content-Type: application/json',
    ));

    $response = json_decode(curl_exec($ch), true);
    var_dump($response);
    curl_close($ch);

    if (!isset($response['result'][0]['id'])) {
        die('Error fetching zone ID');
    }

    return $response['result'][0]['id'];
}


//
// Fetch a Cloudflare record ID
//
function cloudflare_get_record_id(string $cloudflare_zone_id, IPVersion $ip_version) : string {
    $type = match($ip_version) {
        IPVersion::v4 => "A",
        IPVersion::v6 => "AAAA",
    };
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones" . '/' . $cloudflare_zone_id . '/dns_records?type=' . $type . '&name=' . CLOUDFLARE_RECORD_NAME . '.' . CLOUDFLARE_DOMAIN);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'X-Auth-Email: ' . CLOUDFLARE_EMAIL,
        'X-Auth-Key: ' . CLOUDFLARE_API_KEY,
        'Content-Type: application/json',
    ));

    $response = json_decode(curl_exec($ch), true);
    var_dump($response);
    curl_close($ch);

    if (!isset($response['result'][0]['id'])) {
        die('Error fetching DNS record ID');
    }

    return $response['result'][0]['id'];
}


//
// Update the DNS record with IPv4 address
//
function cloudflare_updated_record(string $cloudflare_zone_id, string $cloudflare_record_id, string $ip_address, IPVersion $ip_version) : void {
    $ch = curl_init("https://api.cloudflare.com/client/v4/zones" . '/' . $cloudflare_zone_id . '/dns_records/' . $cloudflare_record_id);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'X-Auth-Email: ' . CLOUDFLARE_EMAIL,
        'X-Auth-Key: ' . CLOUDFLARE_API_KEY,
        'Content-Type: application/json',
    ));

    $data = json_encode(array(
        'type' => $ip_version->type(),
        'name' => CLOUDFLARE_RECORD_NAME . '.' . CLOUDFLARE_DOMAIN,
        'content' => $ip_address,
    ));

    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $response = json_decode(curl_exec($ch), true);
    var_dump($response);
    curl_close($ch);

    if (isset($response['success']) && $response['success']) {
        write_log('Set ' . $ip_version->type() . ' ' . CLOUDFLARE_RECORD_NAME . ' to ' . $ip_address);
    } else {
        write_log('Error updating ' . CLOUDFLARE_RECORD_NAME . ' with IPv4 address: ' . $response['errors'][0]['message']);
    }
}


//
// Verify username and password is correct
//
if(REQUIRE_AUTH && USERNAME !== AUTH_USERNAME && PASSWORD !== AUTH_PASSWORD) {
    header("HTTP/1.1 401 Unauthorized");
    die("HTTP 401 Unauthorized: Access Denied");
}


//
// Update IPv4 Logic
//
if(IPV4_ADDRESS !== "") {
    if(ip_is_equal_to_cached_ip(IPV4_ADDRESS, IPVersion::v4)) {
        write_log(IPVersion::v4->name() . ' for ' . CLOUDFLARE_RECORD_NAME . ' (' . IPV4_ADDRESS . ') is already cached.');
    } else {
        update_cache(
            ip_address: IPV4_ADDRESS,
            ip_version: IPVersion::v4,
        );
        $cloudflare_zone_id = cloudflare_get_zone_id();
        $cloudflare_record_id = cloudflare_get_record_id(
            cloudflare_zone_id: $cloudflare_zone_id,
            ip_version: IPVersion::v4,
        );
        cloudflare_updated_record(
            cloudflare_zone_id: $cloudflare_zone_id,
            cloudflare_record_id: $cloudflare_record_id,
            ip_address: IPV4_ADDRESS,
            ip_version: IPVersion::v4,
        );
    }
}


//
// Update IPv6 Logic
//
if(IPV6_ADDRESS !== "") {
    if(ip_is_equal_to_cached_ip(IPV6_ADDRESS, IPVersion::v6)) {
        write_log(IPVersion::v6->name() . ' for ' . CLOUDFLARE_RECORD_NAME . ' (' . IPV6_ADDRESS . ') is already cached.');
    } else {
        update_cache(
            ip_address: IPV6_ADDRESS,
            ip_version: IPVersion::v6,
        );
        $cloudflare_zone_id = cloudflare_get_zone_id();
        $cloudflare_record_id = cloudflare_get_record_id(
            cloudflare_zone_id: $cloudflare_zone_id,
            ip_version: IPVersion::v6,
        );
        cloudflare_updated_record(
            cloudflare_zone_id: $cloudflare_zone_id,
            cloudflare_record_id: $cloudflare_record_id,
            ip_address: IPV6_ADDRESS,
            ip_version: IPVersion::v6,
        );
    }
}

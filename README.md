# fiskaly-laravel

The fiskaly SDK is a HTTP Laravel client that is needed for accessing the kassensichv.io API v2 that implements a cloud-based, virtual CTSS (Certified Technical Security System) / TSE (Technische Sicherheitseinrichtung) as defined by the German KassenSichV (Kassen­sich­er­ungsver­ord­nung).

### Requirements
PHP 8.0+
Laravel or Lumen 8+

## Integration

### Composer

The PHP SDK is available for a download via [Composer](https://getcomposer.org/).

Packagist - [Package Repository](https://packagist.org/packages/elkadrey/fiskaly-laravel).

Simply execute this command from the shell in your project directory:

```bash
$ composer require elkadrey/fiskaly-laravel
```

Or you can manually add the package to your `composer.json` file:

```json
"require": {
    "elkadrey/fiskaly-laravel": "*"
}
```
then run 
```bash 
$ composer update 
```

### Service

Additionally, to the SDK, you'll also need the fiskaly service. Follow these steps to integrate it into your project:

1. Go to [https://developer.fiskaly.com/downloads#service](https://developer.fiskaly.com/downloads#service)
2. Download the appropriate service build for your platform
3. Start the service

### Get started

#### example 1: Make connection and create a INITIALIZED TSS

```php
<?php namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;

//include the client library
use elkadrey\FiskalyLaravel\FiskalyLaravelClient;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

class Controller extends BaseController
{
    private ?FiskalyLaravelClient $client = null;

    public function __construct()
    {
        //Make the connection
        $this->client = FiskalyLaravelClient::create([
            "api_key" => env("TSE_API_KEY"), 
            "api_secret" => env("TSE_API_SECRET"), 
            "baseUrl" => env("TSE_API_URL", null),
            "token" => Cache::get("tse_token"),
            "token_expire_at" => Cache::get("tse_token_expire_at"),
        ]);
        //Set debug level (2 is default)
        /** Debug levels list
        * 0 = errors only
        * 1 = errors and warning
        * 2 = all with info for each API response
        */
        $this->client->setLog(2);

        //make Auth with token in case empty token or expired
        $token = $this->client->MakeAuth();
        
        //Also you can use getToken
        //$token = $this->client->getToken();
        
        Cache::put("tse_token", $token->access_token);
        Cache::put("tse_token_expire_at", $token->access_token_expires_at);
    }

    public function createTSS(Request $request)
    {
        $this->validate($request, [
            "metadata" => "nullable|array"
        ]);

        try
        {
            //create a ready INITIALIZED TSS (5 steps in one)
            set_time_limit(0);
            $results = $this->client->createTSS($request->metadata);
            return JsonResource::make($results);
        }
        catch(Exception $e)
        {
            //Your exception code here
        }
    }
}
```

#### Custom API usage
To call api like for example: "/tss/{{tssId}}/client/{{$guid}} with method: put" just call the method in the first function then use "_" to spirite the api slashs "/" 
and provide the params data as array, request or collection and the arrangement is boolean in case you want to add GUID automatically for example:
```php
$this->client->put_tss_95a03ad9-61ad-4757-8a1d-57daf47db25c_client($params, true);
```

#### example 2: Make connection and create a Manually TSS

```php
    public function createManuallyTSS(Request $request)
    {
        $this->validate($request, [
            "metadata" => "nullable|array"
        ]);

        try
        {
            //create a ready INITIALIZED TSS (5 steps in one)
            set_time_limit(0);

            //API => method:put /tss/{$guid}
            $tss = $this->client->put_tss($request->metaData, true); //return laravel collection 
            if($tss->state == "CREATED")
            {
                //1- Change state to UNINITIALIZED
                //API => method:patch /tss/{tssid}
                $this->client->{"patch_tss_$tss->_id"}(["state" => "UNINITIALIZED"]);
                $tss->put("state", "UNINITIALIZED");

                //2 - Set admin pin
                $adminPin = uniqid();
                //change admin pin api
                $this->client->changeAdminPin($tss->_id, $tss->admin_puk, $adminPin);                
                $tss->put("adminpin", $adminPin);

                //3- Make admin auth
                //make admin auth
                $this->client->adminAuth($tss->_id, $adminPin);

                //4- Change state to INITIALIZED
                //API => method:patch /tss/{tssid}
                $this->client->{"patch_tss_$tss->_id"}(["state" => "INITIALIZED"]);                
                $tss->put("state", "INITIALIZED");
                return JsonResource::make($tss);
            }
        }
        catch(Exception $e)
        {
            //Your exception code here
        }
    }
```

#### example 3: add client to TSS

```php
public function addClient(string $tssid, Request $request)
{
    $this->validate($request, [
        "metadata" => "nullable|array"
    ]);

    try
    {
        $guid = $this->client->getUUID();
        
        $serial_number = "ERS $guid";
        $info = compact('serial_number', 'metadata');

        //API => method:PUT /tss/{tssid}/client/{guid}
        $results = $this->client->{"put_tss_$tssid"."_client_$guid"}($info);
        return JsonResource::make($results);
    }
    catch(Exception $e)
    {
        //Your exception code here
    }
}
```

#### example 4: Start Transaction

```php
public function startTransaction(string $tssid, string $clientid)
{
    try
    {
        //API => method:PUT /tss/{{tssId}}/tx/{{$guid}}?tx_revision=1
        $results = $this->client->{"put_tss_$tssid"."_tx?tx_revision=1"}(["state" => "ACTIVE", "client_id" => $clientid], true);
        return JsonResource::make($results);
    }
    catch(Exception $e)
    {
        //Your exception code here
    }
}
```

## Related

* [fiskaly.com](https://fiskaly.com)
* [dashboard.fiskaly.com](https://dashboard.fiskaly.com)
* [developer.fiskaly.com Api v2](https://developer.fiskaly.com/api/kassensichv/v2)
* [ Postman collection ](https://developer.fiskaly.com/api/kassensichv/v2#section/How-to-raise-an-Issue)

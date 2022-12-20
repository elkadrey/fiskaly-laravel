<?php

namespace elkadrey\FiskalyLaravel;


use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Log;
use elkadrey\FiskalyLaravel\Responses\Response;
use elkadrey\FiskalyLaravel\Responses\AuthResponse;

class FiskalyLaravelClient
{
    private Collection $config;
    private int $logLevel = 2;
    private ?AuthResponse $token = null;
    protected ?Request $request = null;
    private $logLevels = [
        "0" => "error",
        "1" => "warning",
        "2" => "info"
    ];

    public function __construct(?array $config = null)
    {
        $this->config = new Collection([
            "api_key" => null,
            "api_secret" => null,
            "baseUrl" => "https://kassensichv-middleware.fiskaly.com/api/v2/",
            "token" => null,
            "token_expire_at" => null,
        ]);
        $this->setConfig($config);
    }

    public function setLog(int $level)
    {
        if($level > 2) $level = 2;
        $this->logLevel = $level;
    }

    public function makeLog(string $key, array $context = [], int $level)
    {
        if($level <= $this->logLevel) 
        {
            $levelName = $this->logLevels[$level] ?? null;
            if($levelName) Log::$levelName($key, $context);
        }
    }
    
    public static function create(array $config)
    {
        return new static($config);
    }

    public function setConfig(array $config)
    {
        $this->config = $this->config->merge($config);
        
        if(($token = $this->config->get("token")) && is_string($token) && ($token_expire_at = $this->config->get("token_expire_at")) && is_int($token_expire_at))
        {
            $this->token = new AuthResponse();
            $this->token->setToken($token, $token_expire_at);
        }        
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getURL()
    {
            if(substr($this->config['baseUrl'], strlen($this->config['baseUrl']) - 1, 1) != "/") $this->config['baseUrl'] .= "/";

        return $this->config['baseUrl'];
    }

    public function request()
    {
        $this->checkConfig();
        if(!$this->request) $this->request = new Request($this->getURL());

        return $this->request;
    }

    protected function checkConfig()
    {
        if(!$this->config || !$this->config->has(['api_key', 'api_secret', 'baseUrl']) || $this->config->filter(function($val, $key)
        {
            return empty($val) && !in_array($key, ['token', 'token_expire_at']);
        })->count() > 0) throw new Exception("Missing configurations error !", 422);
    }

    public function getUUID()
    {
        return $this->request()->getUUID();
    }

    public function MakeAuth(bool $force = false)
    {
        if(!$this->token || !$this->token->isAlive())
        {
            if($this->token && !$this->token->isAlive()) $this->makeLog("Make TSE Token", ["message" => "Token expired"], 1);
            $this->token = $this->make("auth", $this->config->only(["api_key", "api_secret"]), false, "post", AuthResponse::class);
            $this->makeLog("Make TSE Token", ["message" => "New token created"], 2);
        }
        else $this->makeLog("Make TSE Token", ["message" => "Using the exists token"], 2);
        return $this->token;
    }

    public function changeAdminPin(string $tssid, string $admin_puk, string $new_admin_pin)
    {
        $results = $this->{"patch_tss_$tssid"."_admin"}(compact('admin_puk', 'new_admin_pin'));
        $this->makeLog("Change admin pin", ["message" => "Admin pin has been changed", compact('admin_puk', 'new_admin_pin')], 2);
        return $results;
    }

    public function adminAuth(string $tssid, string $admin_pin)
    {
        $results = $this->{"post_tss_$tssid"."_admin_auth"}(compact('admin_pin'));
        $this->makeLog("Admin Auth", [compact('admin_pin')], 2);
        return $results;
    }

    public function createTSS(array $metaData, string $adminPin = null)
    {
        $tss = null;
        try
        {
            $tss = $this->put_tss($metaData, true);
            if($tss->state == "CREATED")
            {
                //1- Change state to UNINITIALIZED
                $this->{"patch_tss_$tss->_id"}(["state" => "UNINITIALIZED"]);
                $tss->put("state", "UNINITIALIZED");

                //2 - Set admin pin
                if(!$adminPin) $adminPin = uniqid();
                $this->changeAdminPin($tss->_id, $tss->admin_puk, $adminPin);                
                $tss->put("adminpin", $adminPin);

                //3- Make admin auth
                $this->adminAuth($tss->_id, $adminPin);

                //4- Change state to INITIALIZED
                $this->{"patch_tss_$tss->_id"}(["state" => "INITIALIZED"]);                
                $tss->put("state", "INITIALIZED");
                $this->makeLog("Create TSS Success", ["tss" => $tss->toArray(), ...compact('adminpin')], "2");
                return $tss;
            }
        }
        catch(Exception $e)
        {
            $this->makeLog("Create TSS Error", ["tss" => $tss ? $tss->toArray() : null, ...compact('adminpin')], "0");
            throw new Exception($e->getMessage(), 400);
        }
    }

    public function getToken()
    {
        return $this->token;
    }

    public function make(string $path, array|Collection|HttpRequest $params = [], bool $uuid = false, string $method = "post", $responseClass = null)
    {
        if($this->token && $this->token->isAlive()) $this->request()->setToken($this->token->getToken());
        $this->makeLog("TSS API", compact('path', 'params'), "2");
        if($uuid)
        {
            $url = explode("?", $path);
            $url[0] .= "/".$this->request()->getUUID();
            $path = implode("?", $url);
        }
        $results = $this->request()->{Str::lower($method)}($path, $params, $responseClass);   
        $this->makeLog("TSS API Results", is_a($results, Response::class) ? $results->toArray() ?? [] : [$results], "2");
        return $results;
    }
    

    public function __call($name, $arguments)
    {
        $args = explode("?", Str::lower($name));
        $params = explode("_", $args[0]);
        if(in_array($params[0], ["get", "post", "put", "patch", "delete"]))
        {
            $method = $params[0];
            unset($params[0]);            
            return $this->make(implode("/", $params).(!empty($args[1]) ? "?".$args[1] : ""), $arguments[0] ?? [],  $arguments[1] ?? false, $method);
        }
        else throw new Exception("The method $name dosn't exists !", 400);
    }
}

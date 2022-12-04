<?php

namespace elkadrey\FiskalyLaravel;

use elkadrey\FiskalyLaravel\Responses\AuthResponse;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Log;

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

    public function MakeAuth()
    {
        $this->token = $this->make("auth", $this->config->only(["api_key", "api_secret"]), "post", AuthResponse::class);
    }

    public function changeAdminPin(string $tssid, string $admin_puk, string $new_admin_pin)
    {
        return $this->{"patch_tss_$tssid"."_admin"}(compact('admin_puk', 'new_admin_pin'));
    }

    public function adminAuth(string $tssid, string $admin_pin)
    {
        return $this->{"post_tss_$tssid"."_admin_auth"}(compact('admin_pin'));
    }

    public function createTSS(array $metaData, string $adminPin = null)
    {
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
            $this->makeLog("Create TSS Error", ["tss" => $tss->toArray(), ...compact('adminpin')], "0");
            throw new Exception($e->getMessage(), 400);
        }
    }

    public function getToken()
    {
        return $this->token;
    }

    public function make(string $path, array|Collection|HttpRequest $params = [], string $method = "post", $responseClass = null)
    {
        
        if($this->token && $this->token->isAlive())
        {
            $this->request()->setToken($this->token->getToken());
        }
        return $this->request()->{Str::lower($method)}($path, $params, $responseClass);   
    }
    

    public function __call($name, $arguments)
    {
        $params = explode("_", Str::lower($name));
        if(in_array($params[0], ["get", "post", "put", "patch", "delete"]))
        {
            $method = $params[0];
            unset($params[0]);

            if(!empty($arguments[1]) && $arguments[1] === true) $method = "uuid_$method";
            return $this->make(implode("/", $params), $arguments[0] ?? [], $method);
        }
        else throw new Exception("The method $name dosn't exists !", 400);
    }
}

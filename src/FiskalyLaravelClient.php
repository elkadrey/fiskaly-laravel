<?php

namespace elkadrey\FiskalyLaravel;

use elkadrey\FiskalyLaravel\Responses\AuthResponse;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FiskalyLaravelClient
{
    private Collection $config;

    private ?AuthResponse $token = null;
    protected ?Request $request = null;

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
        if(!$this->config || !$this->config->has(['api_key', 'api_secret', 'baseUrl']) || $this->config->filter(function($val)
        {
            return empty($val);
        })->count() > 0) throw new Exception("Missing configurations error !", 422);
    }

    public function MakeAuth()
    {
        $this->token = $this->request()->post("auth", $this->config->only(["api_key", "api_secret"]), AuthResponse::class);        
    }
}

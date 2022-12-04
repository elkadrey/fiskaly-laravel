<?php

namespace elkadrey\FiskalyLaravel\Responses;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Str;

class AuthResponse extends Response
{
    private ?string $token = null;
    private ?int $tokenExpireAt = null;
    private ?Carbon $tokenExpire = null;

    public function __construct(?ClientResponse $response = null)
    {
        parent::__construct($response);
        $this->setToken($this->access_token, $this->access_token_expires_at);
    }

    public function setToken(?string $token = null, ?int $expireAt = 0)
    {
        $this->token = $token;
        $this->tokenExpireAt = $expireAt;
        if($this->token && !empty($this->tokenExpireAt) && $this->tokenExpireAt > 0)
        {
            $this->tokenExpire = Carbon::parse($this->tokenExpireAt);
            $this->put("tokenExpire", $this->tokenExpire);

            if(!$this->has("access_token")) $this->put("access_token", $token);
            if(!$this->has("access_token_expires_at")) $this->put("access_token_expires_at", $expireAt);
        }
        else $this->tokenExpire = null;
    }

    public function isAlive()
    {
        return $this->tokenExpire && Carbon::now()->isBefore($this->tokenExpire);
    }

    public function __call($name, $arguments)
    {      
        if(isset($this->{$methodName = Str::lower(substr($name, 3))})) return $this->{$methodName};
        else parent::__call($name, $arguments);
    }
}

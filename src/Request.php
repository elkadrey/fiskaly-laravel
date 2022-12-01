<?php

namespace elkadrey\FiskalyLaravel;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Ramsey\Uuid\Uuid;
use elkadrey\FiskalyLaravel\Responses\Response;

class Request
{
    private ?string $mainURL = null;
    private $methodName = null;
    private $headers = [
        "Content-Type" => "application/json",
    ];

    public function __construct(string $url)
    {
        $this->setUrl($url);
    }

    public function make(string $method, string $url, array|Collection $params = [], string $Response = null):? object
    {
        switch(explode("_", $this->methodName)[0])
        {
            case 'uuid':
            case 'guid':
                $url = explode("?", $url);
                if(substr($url[0], strlen($url[0]) - 1, 1) != "/") $url[0] .= "/";
                $url[0] .= $this->getUUID();
                $url = implode("?", $url);
                break;
        }

        $this->methodName = null;
        $results = Http::withHeaders($this->headers)->withoutVerifying()->{$method}($this->mainURL.$url, is_a($params, Collection::class) ? $params->toArray() : $params);

        $results->throw();
        if(!$Response) $Response = Response::class;
        return new $Response($results);
    }

    public function setUrl(string $url)
    {
        $this->mainURL = $url;
    }

    public function setToken(string $token)
    {
        $this->addHeader("Authorization", "Bearer $token");
    }

    public function getUUID()
    {
        return Uuid::uuid4()->toString();
    }

    public function addHeader(string $name, $value)
    {
        $this->headers[$name] = $value;
    }

    public function resetHeaders()
    {
        $this->headers = ["Content-Type" => "application/json"];
    }

    private function allowedMethods()
    {
        $methods = ['get', 'post', 'put', 'patch'];
        $allowed = [];
        foreach($methods as $method)
        {
            $allowed[] = $method;
            $allowed[] = "uuid_$method";
        }

        return $allowed;
    }

    public function __call($name, $arguments)
    {
        if(in_array(strtolower($name), $this->allowedMethods()))
        {
            $this->methodName = $name;
            return call_user_func_array([$this, "make"], array_merge([$name], $arguments));
        }
        else throw new Exception("The method $name dosn't exists !", 400);
    }
}

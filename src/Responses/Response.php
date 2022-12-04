<?php

namespace elkadrey\FiskalyLaravel\Responses;

use Exception;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ArrayAccess;
use ArrayIterator;

class Response implements ArrayAccess
{
    protected ?Collection $items = null;
    protected ?ClientResponse $response = null;
    public function __construct(?ClientResponse $response = null)
    {
        if($response) $this->setResponse($response);
        else $this->items = new Collection();
    }

    public function setResponse(ClientResponse $response)
    {
        $this->response = $response;
        $items = $this->response->body();
        if($this->isJson($items)) $items = json_decode($items);
        elseif(!is_iterable($items)) $items = [$items];
        
        $this->items = new Collection($items);
    }

    public function isJson($item)
    {
        return is_string($item) && (Str::is("{*}", trim($item)) || Str::is("[*]", trim($item)));
    }

    public function getResponse():? ClientResponse
    {
        return $this->response;
    }

    public function __get($key)
    {
        return $this->items->get($key);
    }

    public function __call($name, $arguments)
    {
        if(method_exists($this->items, $name)) return call_user_func_array([$this->items, $name], $arguments);    
        else throw new Exception("The method $name dosn't exists !", 400);
    }

    public function getIterator()
    {
        return new ArrayIterator($this->items);
    }

    public function offsetExists($key)
    {
        return isset($this->items[$key]);
    }

    public function offsetGet($key)
    {
        return $this->items[$key];
    }

    public function offsetSet($key, $value)
    {
        if (is_null($key)) {
            $this->items[] = $value;
        } else {
            $this->items[$key] = $value;
        }
    }

    public function offsetUnset($key)
    {
        unset($this->items[$key]);
    }
}

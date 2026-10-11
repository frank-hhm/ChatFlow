<?php
/**
 * @Date: 2025/6/23 16:44
 */
declare(strict_types=1);
namespace app\websocket;

use EasySwoole\Socket\AbstractInterface\ParserInterface;
use EasySwoole\Socket\Bean\{Caller, Response};

class WebSocketParser implements ParserInterface
{
    /**
     * decode
     */
    public function decode($raw, $client) : ? Caller
    {
        $data = json_decode($raw, true);
//        var_dump($client);
        // new 调用者对象
        $caller =  new Caller();
//
        $class = '\\App\\Websocket\\'. ucfirst($data['class'] ?? 'Index');
        $caller->setControllerClass($class);

        $caller->setAction($data['action'] ?? 'index');

        $args = $data;
        // 设置被调用的Args
        $caller->setArgs($args ?? []);
        return $caller;
    }
    /**
     * encode
     */
    public function encode(Response $response, $client) : ? string
    {
        // 这里返回响应给客户端的信息
        // 这里应当只做统一的encode操作 具体的状态等应当由 Controller处理
        return $response->getMessage();
    }
}
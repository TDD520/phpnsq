<?php

namespace OkStuff\PhpNsq;

use Closure;
use Exception;
use OkStuff\PhpNsq\Cmd\Base as SubscribeCommand;
use OkStuff\PhpNsq\Conn\Pool;
use OkStuff\PhpNsq\Conn\Conn;
use OkStuff\PhpNsq\Utils\Logging;
use OkStuff\PhpNsq\Stream\Reader;
use OkStuff\PhpNsq\Stream\Writer;

class PhpNsq
{
    private $pool;
    private $logger;
    private $channel;
    private $topic;
    private $reader;

    public function __construct($nsq)
    {
        $this->reader = new reader();
        $this->logger = new Logging("PHPNSQ", $nsq["nsq"]["logdir"]);
        $this->pool   = new Pool($nsq);
    }

    public function getLogger()
    {
        return $this->logger;
    }

    public function setChannel(string $channel)
    {
        $this->channel = $channel;

        return $this;
    }

    public function setTopic(string $topic)
    {
        $this->topic = $topic;

        return $this;
    }

    public function auth(string $secret)
    {
        try {
            $conn = $this->pool->getConn();
            $conn->write(Writer::auth($secret));
        } catch (Exception $e) {
            $this->logger->error("auth error", $e);
        }
    }

    public function publish(string $message)
    {
        try {
            $conn = $this->pool->getConn();
            var_dump(stream_context_get_options($conn->getSock()));
            $conn->write(Writer::pub($this->topic, $message));
        } catch (Exception $e) {
            $this->logger->error("publish error", $e);
        }
    }

    public function publishMulti(string ...$messages)
    {
        try {
            $conn = $this->pool->getConn();
            $conn->write(Writer::mpub($this->topic, $messages));
        } catch (Exception $e) {
            $this->logger->error("publish error", $e);
        }
    }

    public function publishDefer(string $message, int $deferTime)
    {
        try {
            $conn = $this->pool->getConn();
            $conn->write(Writer::dpub($this->topic, $deferTime, $message));
        } catch (Exception $e) {
            $this->logger->error("publish error", $e);
        }
    }

    public function subscribe(SubscribeCommand $cmd, Closure $callback)
    {
        try {
            $conn = $this->pool->getConn();
            $sock   = $conn->getSock();

            $cmd->addReadStream($sock, function ($sock) use ($conn, $callback) {
                $this->handleMessage($conn, $callback);
            });

            $conn->write(Writer::sub($this->topic, $this->channel))
                ->write(Writer::rdy(1));
        } catch (Exception $e) {
            $this->logger->error("subscribe error", $e);
        }
    }

    protected function handleMessage(Conn $conn, Closure $callback)
    {
        $reader = $this->reader->bindConn($conn)->bindFrame();

        if ($reader->isHeartbeat()) {
            $conn->write(Writer::nop());
        } elseif ($reader->isMessage()) {

            $msg = $reader->getMessage();
            try {
                call_user_func($callback, $msg);
            } catch (Exception $e) {
                $this->logger->error("Will be requeued: ", $e->getMessage());

                $conn->write(Writer::touch($msg->getId()))
                    ->write(Writer::req(
                        $msg->getId(),
                        $conn->getConfig()->get("defaultRequeueDelay")["default"]
                    ));
            }

            $conn->write(Writer::fin($msg->getId()))
                ->write(Writer::rdy(1));
        } elseif ($reader->isOk()) {
            $this->logger->info('Ignoring "OK" frame in SUB loop');
        } else {
            $this->logger->error("Error/unexpected frame received: ", $reader);
        }
    }
}

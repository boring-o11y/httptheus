<?php

namespace BoringO11y\Httptheus\Metrics;

use Prometheus\Storage\Redis;
use Prometheus\Storage\RedisClients\PHPRedis;

/**
 * The ext-redis adapter, with its keys under a prefix of our own.
 *
 * The client takes no prefix option and its own is a process-wide static
 * shared with every other user of the library, so without this the `redis`
 * driver would write into the same PROMETHEUS_* keyspace as any other app on
 * that Redis, and httptheus:wipe would delete their metrics along with ours.
 * Setting OPT_PREFIX on the connection is the mechanism the adapter already
 * understands: its reads and its wipe both account for it.
 */
class PrefixedRedis extends Redis
{
    /**
     * @param  mixed[]  $options
     */
    public function __construct(array $options, string $prefix)
    {
        parent::__construct($options);

        // The connection is built, unconnected, inside the client and never
        // exposed. The prefix can be set before connecting, so this keeps the
        // client's lazy connect and its merge over setDefaultOptions().
        $client = $this->redis;

        if ($client instanceof PHPRedis) {
            (fn () => $this->redis->setOption(\Redis::OPT_PREFIX, $prefix))->call($client);
        }
    }
}

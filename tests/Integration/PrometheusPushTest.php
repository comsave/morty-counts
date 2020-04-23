<?php

namespace Comsave\Tests\Integration;

use Comsave\MortyCountsBundle\Factory\GuzzleHttpClientFactory;
use Comsave\MortyCountsBundle\Factory\JmsSerializerFactory;
use Comsave\MortyCountsBundle\Factory\PushGatewayFactory;
use Comsave\MortyCountsBundle\Factory\RedisStorageAdapterFactory;
use Comsave\MortyCountsBundle\Services\PrometheusClient;
use Comsave\MortyCountsBundle\Services\PushGatewayClient;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;

class PrometheusPushTest extends TestCase
{
    /** @var PrometheusClient */
    private $prometheusClient;

    /** @var PushGatewayClient */
    private $pushGatewayClient;

    /** @var string */
    private $jobName = 'my_custom_service_job';

    /** @var string */
    private $instanceName = '127.0.0.1:9000';

    public function setUp(): void
    {
        $this->prometheusClient = new PrometheusClient(
            'prometheus:9090',
            JmsSerializerFactory::build(),
            GuzzleHttpClientFactory::build()
        );

        $registryStorageAdapter = RedisStorageAdapterFactory::build('redis', 6379);
        $registry = new CollectorRegistry($registryStorageAdapter);

        $this->pushGatewayClient = new PushGatewayClient(
            $registry,
            $registryStorageAdapter,
            PushGatewayFactory::build('pushgateway:9191'),
            $this->jobName,
            $this->instanceName
        );
        $this->pushGatewayClient->flush();
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Prometheus\Exception\MetricsRegistrationException
     * @throws \Prometheus\Exception\StorageException
     */
    public function testPushesCounterMetric(): void
    {
        $metricNamespace = 'test';
        $metricName = 'some_counter';
        $metricFullName = sprintf('%s_%s', $metricNamespace, $metricName);

        $counter = $this->pushGatewayClient->counter(
            $metricNamespace,
            $metricName,
            'it increases',
            ['type']
        );
        $counter->incBy(5, ['blue']);
        $this->pushGatewayClient->push();

        sleep(3); // wait for Prometheus to pull the metrics from PushGateway

        $results = $this->prometheusClient->query([
            'query' => $metricFullName,
        ])->getData()->getResults();

        $this->assertCount(1, $results);
        $this->assertEquals($metricFullName, $results[0]->getMetric()['__name__']);
        $this->assertEquals('blue', $results[0]->getMetric()['type']);
        $this->assertEquals(5, $results[0]->getValue());
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Prometheus\Exception\MetricNotFoundException
     * @throws \Prometheus\Exception\MetricsRegistrationException
     * @throws \Prometheus\Exception\StorageException
     */
    public function testPushesCounterMetricAndIncreases(): void
    {
        $metricNamespace = 'test';
        $metricName = 'some_counter_2';
        $metricFullName = sprintf('%s_%s', $metricNamespace, $metricName);

        $counter = $this->pushGatewayClient->counter(
            $metricNamespace,
            $metricName,
            'it increases',
            ['type']
        );
        $counter->incBy(5, ['blue']);
        $this->pushGatewayClient->push();

        sleep(3); // wait for Prometheus to pull the metrics from PushGateway

        $results = $this->prometheusClient->query([
            'query' => $metricFullName,
        ])->getData()->getResults();

        $this->assertCount(1, $results);
        $this->assertEquals($metricFullName, $results[0]->getMetric()['__name__']);
        $this->assertEquals('blue', $results[0]->getMetric()['type']);
        $this->assertEquals(5, $results[0]->getValue());

        // todo: integrate initial (last) value fetch for the COUNTER
        // todo: this should work even after clearing redis cache which should be done after every push
        $counter = $this->pushGatewayClient->counter(
            $metricNamespace,
            $metricName
        );
        $counter->inc(['blue']);
        $this->pushGatewayClient->push();

        sleep(3); // wait for Prometheus to pull the metrics from PushGateway

        $results = $this->prometheusClient->query([
            'query' => $metricFullName,
        ])->getData()->getResults();

        $this->assertCount(1, $results);
        $this->assertEquals($metricFullName, $results[0]->getMetric()['__name__']);
        $this->assertEquals('blue', $results[0]->getMetric()['type']);
        $this->assertEquals(6, $results[0]->getValue());
    }
}
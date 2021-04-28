<?php

namespace DTL\Extension\Fink\Model;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use DOMDocument;
use DOMElement;
use DOMXPath;
use DTL\Extension\Fink\Model\Exception\InvalidUrl;
use Generator;

class Crawler
{
    /**
     * @var HttpClient
     */
    private $client;

    public function __construct(HttpClient $client)
    {
        $this->client = $client;
    }

    public function crawl(Url $documentUrl, UrlQueue $queue, ReportBuilder $report): Generator
    {
        $start = microtime(true);
        $report->withReferringElement($documentUrl->referringElement());
        $response = yield $this->client->request(new Request((string) $documentUrl));
        $time = (microtime(true) - $start) * 1E6;
        assert($response instanceof Response);

        // if there was a redirect, use the redirect URL as the base
        $ultimateUrl = $documentUrl->withPsiUri($response->getRequest()->getUri());

        $report->withRequestTime((int) $time);

        assert($response instanceof Response);
        $report->withStatus($response->getStatus());
        $report->withHttpVersion($response->getProtocolVersion());

        $body = yield $response->getBody()->buffer();

        if (!$body) {
            return;
        }

        $xpath = $this->loadXpath($body);

        if ($xpath === false) {
            return;
        }

        $this->enqueueLinks($this->loadXpath($body), $ultimateUrl, $report, $queue);
        $this->enqueueImages($this->loadXpath($body), $ultimateUrl, $report, $queue);
    }

    private function enqueueLinks(DOMXPath $xpath, Url $documentUrl, ReportBuilder $report, UrlQueue $queue): void
    {
        foreach ($xpath->query('//a') as $linkElement) {
            assert($linkElement instanceof DOMElement);
            $href = $linkElement->getAttribute('href');

            if (!$href) {
                continue;
            }

            try {
                $url = $documentUrl->resolveUrl($href, ReferringElement::fromDOMNode($linkElement));
            } catch (InvalidUrl $invalidUrl) {
                $report->withException($invalidUrl);
                continue;
            }

            if (!$url->isHttp()) {
                continue;
            }

            $queue->enqueue($url);
        }
    }

    private function enqueueImages(DOMXPath $xpath, Url $documentUrl, ReportBuilder $report, UrlQueue $queue): void
    {
        foreach ($xpath->query('//img') as $imageElement) {
            assert($imageElement instanceof DOMElement);
            $src = $imageElement->getAttribute('src');

            if (!$src) {
                continue;
            }

            try {
                $url = $documentUrl->resolveUrl($src, ReferringElement::fromDOMNode($imageElement));
            } catch (InvalidUrl $invalidUrl) {
                $report->withException($invalidUrl);
                continue;
            }

            if (!$url->isHttp()) {
                continue;
            }

            $queue->enqueue($url);
        }
    }

    private function loadXpath(string $body): DOMXPath
    {
        $dom = new DOMDocument('1.0');
        @$dom->loadHTML($body);

        return new DOMXPath($dom);
    }
}

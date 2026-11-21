<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

/** HEPTAPOD_DELIVERY_PRIVATE_HOSTS: the exact hosts the instance Owner allows as private delivery targets. */
final class DeliveryPrivateHosts
{
    public static function lists(string $host): bool
    {
        return in_array(TargetHost::normalize($host), config()->array('heptapod.outbound.delivery_private_hosts'), true);
    }

    /** Delivery URLs carry credentials and alert content: HTTPS, or plain HTTP only to a listed private host. */
    public static function allowsScheme(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);

        return $scheme === 'https' || ($scheme === 'http' && is_string($host) && $host !== '' && self::lists($host));
    }
}

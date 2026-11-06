<?php

namespace App\Services\Infra;

/**
 * Real locations of the chain's own infrastructure (config/tpix_infra.php).
 *
 * The only source of map coordinates for project-run nodes. No match = no location
 * (null), never a guess.
 */
class InfraSites
{
    /** /ip4/<IPv4>/tcp/<port>/p2p/<peer id> — same shape the masternode app accepts */
    private const MULTIADDR_RE = '#^/ip4/(\d{1,3}(?:\.\d{1,3}){3})/tcp/(\d{1,5})/p2p/([1-9A-HJ-NP-Za-km-z]{20,80})$#';

    /** @return list<array<string,mixed>> */
    public function sites(): array
    {
        $out = [];
        foreach ((array) config('tpix_infra.sites', []) as $site) {
            if (! is_array($site) || ! $this->hasCoordinates($site)) {
                continue;
            }
            $out[] = $site;
        }

        return $out;
    }

    public function siteForIp(string $ip): ?array
    {
        foreach ($this->sites() as $site) {
            if (($site['ip'] ?? null) === $ip) {
                return $site;
            }
        }

        return null;
    }

    /** The site the IBFT validator set runs on — null when none is listed. */
    public function validatorSite(): ?array
    {
        foreach ($this->sites() as $site) {
            if (! empty($site['runs_validators'])) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Location fields shared by validator and bootnode entries.
     *
     * @return array{country_code:?string, country_name:?string, city:?string, latitude:?float,
     *               longitude:?float, location_known:bool, site_id:?string, provider:?string}
     */
    public function locationFields(?array $site): array
    {
        if ($site === null) {
            return [
                'country_code' => null,
                'country_name' => null,
                'city' => null,
                'latitude' => null,
                'longitude' => null,
                'location_known' => false,
                'site_id' => null,
                'provider' => null,
            ];
        }

        return [
            'country_code' => isset($site['country_code']) ? strtoupper((string) $site['country_code']) : null,
            'country_name' => $site['country_name'] ?? null,
            'city' => $site['city'] ?? null,
            'latitude' => (float) $site['latitude'],
            'longitude' => (float) $site['longitude'],
            'location_known' => true,
            'site_id' => $site['id'] ?? null,
            'provider' => $site['provider'] ?? null,
        ];
    }

    /**
     * Public bootnodes (masternode.public_bootnodes) as map entries.
     *
     * The multiaddr is already public (GET /api/v1/masternode/config).
     * Malformed entries are skipped; an IP with no listed site has no location.
     *
     * @return list<array<string,mixed>>
     */
    public function bootnodes(): array
    {
        $out = [];
        $seen = [];
        foreach ((array) config('masternode.public_bootnodes', []) as $addr) {
            $addr = trim((string) $addr);
            if (! preg_match(self::MULTIADDR_RE, $addr, $m) || isset($seen[$addr])) {
                continue;
            }
            $port = (int) $m[2];
            if ($port < 1 || $port > 65535 || filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            $seen[$addr] = true;

            $out[] = [
                'type' => 'bootnode',
                'multiaddr' => $addr,
                'ip' => $m[1],
                'port' => $port,
                'peer_id' => $m[3],
            ] + $this->locationFields($this->siteForIp($m[1]));
        }

        return $out;
    }

    private function hasCoordinates(array $site): bool
    {
        $lat = $site['latitude'] ?? null;
        $lng = $site['longitude'] ?? null;

        return is_numeric($lat) && is_numeric($lng)
            && (float) $lat >= -90 && (float) $lat <= 90
            && (float) $lng >= -180 && (float) $lng <= 180;
    }
}

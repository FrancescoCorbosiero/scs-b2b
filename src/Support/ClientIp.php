<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Risolve l'IP reale del client. In produzione ci pensa già nginx
 * (set_real_ip_from sulla subnet del proxy); questo helper è la cintura
 * di sicurezza: accetta X-Forwarded-For SOLO se REMOTE_ADDR appartiene
 * a una subnet fidata (TRUSTED_PROXY_SUBNETS), mai in modo incondizionato.
 */
final class ClientIp
{
    public function __construct(private readonly Config $config)
    {
    }

    public function resolve(): string
    {
        $remote = $this->serverString('REMOTE_ADDR', '0.0.0.0');
        $subnets = $this->trustedSubnets();
        if ($subnets === [] || !self::inSubnets($remote, $subnets)) {
            return $remote;
        }
        $forwarded = $this->serverString('HTTP_X_FORWARDED_FOR', '');
        if ($forwarded === '') {
            return $remote;
        }
        $chain = array_map(trim(...), explode(',', $forwarded));
        // il client reale è il primo IP non fidato partendo da destra
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $ip = $chain[$i];
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return $remote;
            }
            if (!self::inSubnets($ip, $subnets)) {
                return $ip;
            }
        }

        return $chain[0] !== '' ? $chain[0] : $remote;
    }

    /** @return list<string> */
    private function trustedSubnets(): array
    {
        $raw = trim($this->config->str('TRUSTED_PROXY_SUBNETS'));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $s): bool => $s !== ''));
    }

    /** @param list<string> $subnets */
    private static function inSubnets(string $ip, array $subnets): bool
    {
        foreach ($subnets as $subnet) {
            if (self::inSubnet($ip, $subnet)) {
                return true;
            }
        }

        return false;
    }

    private static function inSubnet(string $ip, string $subnet): bool
    {
        if (!str_contains($subnet, '/')) {
            return $ip === $subnet;
        }
        [$base, $bits] = explode('/', $subnet, 2);
        $ipBin = inet_pton($ip);
        $baseBin = inet_pton($base);
        if ($ipBin === false || $baseBin === false || strlen($ipBin) !== strlen($baseBin)) {
            return false;
        }
        $prefix = (int) $bits;
        $maxBits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }
        $fullBytes = intdiv($prefix, 8);
        $remainder = $prefix % 8;
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($baseBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = ~(0xFF >> $remainder) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($baseBin[$fullBytes]) & $mask);
    }

    private function serverString(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}

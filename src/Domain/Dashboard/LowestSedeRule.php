<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * Regla «Sede más baja» (Demo 4, métrica 5):
 * - Solo compiten las sedes que terminaron al menos 10 paquetes en el período.
 * - Gana la de menor porcentaje de entregas exitosas; si empatan, la que tiene más fallidos.
 * - Las sedes con menos de 10 paquetes terminados se marcan como «Datos insuficientes».
 *   Con menos de 10, un solo fallo mueve el porcentaje más de 10 puntos y la comparación no es justa.
 */
final class LowestSedeRule
{
    public const MIN_FINISHED = 10;

    public static function hasEnoughData(int $finished): bool
    {
        return $finished >= self::MIN_FINISHED;
    }

    /**
     * @param list<array{id: int, finished: int, delivered: int, failed: int}> $sedes
     * @return int|null id de la sede más baja, o null si ninguna tiene datos suficientes
     */
    public static function lowest(array $sedes): ?int
    {
        $lowest = null;

        foreach ($sedes as $sede) {
            if (!self::hasEnoughData($sede['finished'])) {
                continue;
            }
            if ($lowest === null || self::isLower($sede, $lowest)) {
                $lowest = $sede;
            }
        }

        return $lowest['id'] ?? null;
    }

    /**
     * Compara tasas con producto cruzado para no depender del redondeo a 1 decimal.
     *
     * @param array{id: int, finished: int, delivered: int, failed: int} $candidate
     * @param array{id: int, finished: int, delivered: int, failed: int} $current
     */
    private static function isLower(array $candidate, array $current): bool
    {
        $candidateRate = $candidate['delivered'] * $current['finished'];
        $currentRate = $current['delivered'] * $candidate['finished'];

        if ($candidateRate !== $currentRate) {
            return $candidateRate < $currentRate;
        }
        if ($candidate['failed'] !== $current['failed']) {
            return $candidate['failed'] > $current['failed'];
        }

        // Desempate final determinista: el id menor.
        return $candidate['id'] < $current['id'];
    }
}

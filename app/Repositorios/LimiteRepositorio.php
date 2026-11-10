<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;

/**
 * Contadores del rate limiting (tabla limites_peticiones).
 */
final class LimiteRepositorio
{
    /**
     * Suma un intento y devuelve cuántos lleva en la ventana actual.
     *
     * Todo ocurre en UNA sentencia (INSERT ... ON CONFLICT DO UPDATE), que
     * PostgreSQL ejecuta de forma atómica: dos peticiones simultáneas nunca
     * leen el mismo contador. Si la ventana ya caducó, el contador vuelve a 1.
     *
     * @return array{contador: int, reinicio: int} reinicio = timestamp Unix en que se libera
     */
    public function incrementar(string $ambito, string $clave, int $ventanaSegundos): array
    {
        // Limpieza ocasional (1 de cada 100 llamadas) de contadores antiguos
        if (random_int(1, 100) === 1) {
            BaseDatos::conexion()->exec("DELETE FROM limites_peticiones WHERE inicio_ventana < now() - interval '1 day'");
        }

        // Con consultas preparadas reales no se puede repetir un mismo :nombre,
        // por eso la ventana se pasa tres veces (:v1, :v2, :v3)
        $sql = <<<SQL
            INSERT INTO limites_peticiones AS l (clave, ambito, inicio_ventana, contador)
            VALUES (:clave, :ambito, now(), 1)
            ON CONFLICT (clave) DO UPDATE SET
                contador = CASE WHEN l.inicio_ventana <= now() - make_interval(secs => :v1)
                                THEN 1 ELSE l.contador + 1 END,
                inicio_ventana = CASE WHEN l.inicio_ventana <= now() - make_interval(secs => :v2)
                                      THEN now() ELSE l.inicio_ventana END
            RETURNING l.contador,
                      EXTRACT(EPOCH FROM l.inicio_ventana + make_interval(secs => :v3))::bigint AS reinicio
            SQL;

        $consulta = BaseDatos::conexion()->prepare($sql);
        $consulta->execute([
            'clave'  => $clave,
            'ambito' => $ambito,
            'v1'     => $ventanaSegundos,
            'v2'     => $ventanaSegundos,
            'v3'     => $ventanaSegundos,
        ]);
        $fila = $consulta->fetch();

        return ['contador' => (int) $fila['contador'], 'reinicio' => (int) $fila['reinicio']];
    }

    public function borrar(string $clave): void
    {
        BaseDatos::conexion()
            ->prepare('DELETE FROM limites_peticiones WHERE clave = :clave')
            ->execute(['clave' => $clave]);
    }
}

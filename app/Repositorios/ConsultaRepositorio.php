<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;

/**
 * Acceso a datos de las consultas del formulario de contacto. Solo SQL: las
 * reglas (validación, quién puede verlas) están en ConsultaServicio.
 */
final class ConsultaRepositorio
{
    private const COLUMNAS = 'id, nombre, email, asunto, mensaje, estado, fecha_consulta';

    /** @return array la consulta recién guardada (con su id, estado y fecha) */
    public function crear(string $nombre, string $email, string $asunto, string $mensaje): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'INSERT INTO consultas (nombre, email, asunto, mensaje)
             VALUES (:nombre, :email, :asunto, :mensaje)
             RETURNING ' . self::COLUMNAS
        );
        $consulta->execute(['nombre' => $nombre, 'email' => $email, 'asunto' => $asunto, 'mensaje' => $mensaje]);
        return $consulta->fetch();
    }

    /**
     * @param bool $bloquear FOR UPDATE: si dos administradores cambian el estado
     *                       a la vez, el segundo espera y la auditoría registra
     *                       bien de qué estado partía cada uno
     */
    public function buscarPorId(int $id, bool $bloquear = false): ?array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM consultas WHERE id = :id' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $consulta->execute(['id' => $id]);
        return $consulta->fetch() ?: null;
    }

    public function cambiarEstado(int $id, string $estado): void
    {
        BaseDatos::conexion()->prepare('UPDATE consultas SET estado = :estado WHERE id = :id')
            ->execute(['estado' => $estado, 'id' => $id]);
    }

    /** Listado paginado, de la más reciente a la más antigua, con filtro opcional por estado */
    public function listar(?string $estado, int $limite, int $desplazamiento): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT ' . self::COLUMNAS . '
             FROM consultas
             WHERE (CAST(:estado AS varchar) IS NULL OR estado = :estado_filtro)
             ORDER BY fecha_consulta DESC, id DESC
             LIMIT :limite OFFSET :desplazamiento'
        );
        $consulta->execute([
            'estado'         => $estado,
            'estado_filtro'  => $estado,
            'limite'         => $limite,
            'desplazamiento' => $desplazamiento,
        ]);
        return $consulta->fetchAll();
    }

    public function contar(?string $estado): int
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT count(*) FROM consultas WHERE (CAST(:estado AS varchar) IS NULL OR estado = :estado_filtro)'
        );
        $consulta->execute(['estado' => $estado, 'estado_filtro' => $estado]);
        return (int) $consulta->fetchColumn();
    }
}

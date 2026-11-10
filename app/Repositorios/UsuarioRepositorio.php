<?php

declare(strict_types=1);

namespace App\Repositorios;

use App\Nucleo\BaseDatos;

/**
 * Acceso a datos de usuarios y clientes. Solo SQL: las reglas de negocio
 * están en los servicios. Todas las consultas son preparadas.
 */
final class UsuarioRepositorio
{
    /** Columnas públicas: nunca se devuelve el hash fuera de buscarPorEmail */
    private const COLUMNAS = 'u.id, u.nombre, u.apellidos, u.email, r.codigo AS rol, u.activo,
                              u.fecha_creacion, u.ultimo_acceso';

    /** Para el login: incluye el hash de la contraseña */
    public function buscarPorEmail(string $email): ?array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT ' . self::COLUMNAS . ', u.password_hash
             FROM usuarios u JOIN roles r ON r.id = u.rol_id
             WHERE lower(u.email) = lower(:email)'
        );
        $consulta->execute(['email' => $email]);
        return $consulta->fetch() ?: null;
    }

    public function buscarPorId(int $id): ?array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = :id'
        );
        $consulta->execute(['id' => $id]);
        return $consulta->fetch() ?: null;
    }

    public function emailRegistrado(string $email): bool
    {
        $consulta = BaseDatos::conexion()->prepare('SELECT 1 FROM usuarios WHERE lower(email) = lower(:email)');
        $consulta->execute(['email' => $email]);
        return (bool) $consulta->fetchColumn();
    }

    /**
     * Crea la cuenta (usuarios) y el perfil comercial (clientes).
     * Debe llamarse dentro de BaseDatos::transaccion(): son dos tablas y, si
     * falla la segunda, no puede quedar una cuenta sin perfil.
     */
    public function crearCliente(array $datos, string $passwordHash): int
    {
        $pdo = BaseDatos::conexion();

        $usuario = $pdo->prepare(
            "INSERT INTO usuarios (rol_id, nombre, apellidos, email, password_hash)
             VALUES ((SELECT id FROM roles WHERE codigo = 'cliente'), :nombre, :apellidos, :email, :hash)
             RETURNING id"
        );
        $usuario->execute([
            'nombre'    => $datos['nombre'],
            'apellidos' => $datos['apellidos'],
            'email'     => $datos['email'],
            'hash'      => $passwordHash,
        ]);
        $id = (int) $usuario->fetchColumn();

        $pdo->prepare(
            'INSERT INTO clientes (usuario_id, tipo_cliente, razon_social, nif, telefono,
                                   direccion, codigo_postal, ciudad, provincia)
             VALUES (:usuario_id, :tipo_cliente, :razon_social, :nif, :telefono,
                     :direccion, :codigo_postal, :ciudad, :provincia)'
        )->execute([
            'usuario_id'    => $id,
            'tipo_cliente'  => $datos['tipo_cliente'],
            'razon_social'  => $datos['razon_social'],
            'nif'           => $datos['nif'],
            'telefono'      => $datos['telefono'],
            'direccion'     => $datos['direccion'],
            'codigo_postal' => $datos['codigo_postal'],
            'ciudad'        => $datos['ciudad'],
            'provincia'     => $datos['provincia'],
        ]);

        return $id;
    }

    public function registrarAcceso(int $id): void
    {
        BaseDatos::conexion()->prepare('UPDATE usuarios SET ultimo_acceso = now() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /** Si PHP sube el coste de bcrypt, el hash se renueva en el siguiente login */
    public function actualizarHash(int $id, string $passwordHash): void
    {
        BaseDatos::conexion()->prepare('UPDATE usuarios SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => $passwordHash, 'id' => $id]);
    }

    /** Listado paginado, con filtro opcional por rol */
    public function listar(?string $rol, int $limite, int $desplazamiento): array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT ' . self::COLUMNAS . '
             FROM usuarios u JOIN roles r ON r.id = u.rol_id
             WHERE (CAST(:rol AS varchar) IS NULL OR r.codigo = :rol_filtro)
             ORDER BY u.id
             LIMIT :limite OFFSET :desplazamiento'
        );
        $consulta->execute([
            'rol'            => $rol,
            'rol_filtro'     => $rol,
            'limite'         => $limite,
            'desplazamiento' => $desplazamiento,
        ]);
        return $consulta->fetchAll();
    }

    public function contar(?string $rol): int
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT count(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id
             WHERE (CAST(:rol AS varchar) IS NULL OR r.codigo = :rol_filtro)'
        );
        $consulta->execute(['rol' => $rol, 'rol_filtro' => $rol]);
        return (int) $consulta->fetchColumn();
    }
}

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

    /**
     * Perfil comercial de un usuario cliente. null si la cuenta no es de un
     * cliente (admin y almacén no tienen perfil ni pueden hacer pedidos).
     */
    public function perfilCliente(int $usuarioId): ?array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT id, telefono, direccion, codigo_postal, ciudad, provincia
             FROM clientes WHERE usuario_id = :usuario_id'
        );
        $consulta->execute(['usuario_id' => $usuarioId]);
        return $consulta->fetch() ?: null;
    }

    /**
     * Perfil que ve el propio cliente: datos de la cuenta (usuarios) y del
     * perfil comercial (clientes) juntos. null si la cuenta no tiene perfil de
     * cliente (admin y almacén): el JOIN no encuentra fila en clientes.
     */
    public function perfilCompleto(int $usuarioId): ?array
    {
        $consulta = BaseDatos::conexion()->prepare(
            'SELECT u.id, u.nombre, u.apellidos, u.email,
                    c.telefono, c.direccion, c.codigo_postal, c.ciudad, c.provincia
             FROM usuarios u JOIN clientes c ON c.usuario_id = u.id
             WHERE u.id = :usuario_id'
        );
        $consulta->execute(['usuario_id' => $usuarioId]);
        return $consulta->fetch() ?: null;
    }

    /**
     * Cambia el nombre y los apellidos de la cuenta. Solo se tocan las columnas
     * que llegan en $campos y que estén en la lista fija: el nombre de la
     * columna nunca sale de la petición, solo su valor (que va como parámetro).
     *
     * @param array<string, string|null> $campos ej. ['nombre' => 'Lucía']
     */
    public function actualizarNombre(int $usuarioId, array $campos): void
    {
        $this->actualizarColumnas('usuarios', 'id', $usuarioId, $campos, ['nombre', 'apellidos']);
    }

    /**
     * Cambia los datos de contacto y envío del perfil de cliente. Va junto a
     * actualizarNombre() dentro de una misma transacción del servicio.
     *
     * @param array<string, string|null> $campos ej. ['telefono' => '600000101']
     */
    public function actualizarPerfilCliente(int $usuarioId, array $campos): void
    {
        $this->actualizarColumnas('clientes', 'usuario_id', $usuarioId, $campos,
            ['telefono', 'direccion', 'codigo_postal', 'ciudad', 'provincia']);
    }

    public function emailRegistrado(string $email): bool
    {
        $consulta = BaseDatos::conexion()->prepare('SELECT 1 FROM usuarios WHERE lower(email) = lower(:email)');
        $consulta->execute(['email' => $email]);
        return (bool) $consulta->fetchColumn();
    }

    /**
     * Crea la cuenta (usuarios) y el perfil del cliente (clientes).
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
            'INSERT INTO clientes (usuario_id, telefono, direccion, codigo_postal, ciudad, provincia)
             VALUES (:usuario_id, :telefono, :direccion, :codigo_postal, :ciudad, :provincia)'
        )->execute([
            'usuario_id'    => $id,
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

    /**
     * UPDATE de las columnas permitidas que vengan en $campos. La tabla, la
     * clave y la lista de columnas las fija cada método público (nunca la
     * petición), así que lo único que se concatena son nombres escritos en
     * este archivo; los valores van siempre como parámetros.
     *
     * @param list<string> $permitidas
     */
    private function actualizarColumnas(string $tabla, string $clave, int $id, array $campos, array $permitidas): void
    {
        $columnas = array_values(array_intersect($permitidas, array_keys($campos)));
        if ($columnas === []) {
            return;   // nada que cambiar en esta tabla
        }

        $asignaciones = array_map(static fn (string $columna): string => "{$columna} = :{$columna}", $columnas);
        $parametros = array_intersect_key($campos, array_flip($columnas)) + ['id' => $id];

        BaseDatos::conexion()
            ->prepare("UPDATE {$tabla} SET " . implode(', ', $asignaciones) . " WHERE {$clave} = :id")
            ->execute($parametros);
    }
}

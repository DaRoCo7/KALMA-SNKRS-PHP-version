# Ejemplos de petición y respuesta de la API

Peticiones y respuestas **reales**, capturadas en la ejecución de la colección de pruebas descrita en [`resultados-coleccion.md`](resultados-coleccion.md). La especificación completa de cada ruta (parámetros, permisos y códigos) está en el [punto 9 del contrato de integración](../contrato-integracion.md#9-contrato-de-la-api-para-el-front).

- Las listas se recortan a sus primeros elementos (`"… N más"`) y el token CSRF se acorta.
- Todas las peticiones llevan la cookie de sesión `KALMA_SESION`. Las que modifican datos (`POST`, `PATCH`, `DELETE`) llevan además `X-CSRF-Token`, que se obtiene en `GET /api/sesion` y cambia al iniciar sesión.
- Éxito: `{"datos": ...}` (los listados añaden `paginacion`). Error: `{"error": {"codigo", "mensaje", "detalles"}}`.

27 ejemplos sobre 19 rutas distintas, con respuestas correctas y de error.

## Sistema y sesión

### 1. Estado del servicio (200)

`GET /health` · permiso: Público

```http
GET /health
```

Respuesta `200 OK`

```json
{
  "app": "disponible",
  "base_datos": "disponible"
}
```

### 2. Método no permitido (405)

`DELETE /api` · permiso: Público

```http
DELETE /api
X-CSRF-Token: <token de la sesión>
```

Respuesta `405 Method Not Allowed`

```json
{
  "error": {
    "codigo": 405,
    "mensaje": "Método no permitido en esta ruta",
    "detalles": {
      "permitidos": "GET"
    }
  }
}
```

### 3. Sesión anónima (200)

`GET /api/sesion` · permiso: Público

```http
GET /api/sesion
```

Respuesta `200 OK`

```json
{
  "datos": {
    "autenticado": false,
    "usuario": null,
    "csrf_token": "ccaf7caae9…"
  }
}
```

### 4. Registro de cliente (201)

`POST /api/registro` · permiso: Público

```http
POST /api/registro
Content-Type: application/json
X-CSRF-Token: <token de la sesión>

{
  "nombre": "Carla",
  "apellidos": "Prueba Postman",
  "email": "cliente.1795120959143@correo.test",
  "password": "Zapas2026",
  "telefono": "600 000 999",
  "codigo_postal": "28001",
  "ciudad": "Madrid"
}
```

Respuesta `201 Created` · `Location: /api/sesion`

```json
{
  "datos": {
    "usuario": {
      "id": 17,
      "nombre": "Carla",
      "apellidos": "Prueba Postman",
      "email": "cliente.1795120959143@correo.test",
      "rol": "cliente"
    },
    "csrf_token": "6677894a4e…"
  }
}
```

### 5. Registro con datos no válidos (400)

`POST /api/registro` · permiso: Público

```http
POST /api/registro
Content-Type: application/json
X-CSRF-Token: <token de la sesión>

{
  "nombre": "",
  "apellidos": "Prueba",
  "email": "no-es-un-email",
  "password": "corta",
  "codigo_postal": "2800"
}
```

Respuesta `400 Bad Request`

```json
{
  "error": {
    "codigo": 400,
    "mensaje": "Hay datos no válidos",
    "detalles": {
      "errores": {
        "nombre": "Obligatorio",
        "email": "Email no válido",
        "password": "Mínimo 8 caracteres",
        "codigo_postal": "Código postal de 5 cifras"
      }
    }
  }
}
```

### 6. Login con contraseña incorrecta (401)

`POST /api/login` · permiso: Público

```http
POST /api/login
Content-Type: application/json
X-CSRF-Token: <token de la sesión>

{
  "email": "cliente.1795120959143@correo.test",
  "password": "Incorrecta1"
}
```

Respuesta `401 Unauthorized`

```json
{
  "error": {
    "codigo": 401,
    "mensaje": "Email o contraseña incorrectos"
  }
}
```

### 7. Login de la clienta (200)

`POST /api/login` · permiso: Público

```http
POST /api/login
Content-Type: application/json
X-CSRF-Token: <token de la sesión>

{
  "email": "cliente@kalma.test",
  "password": "Kalma2026!"
}
```

Respuesta `200 OK`

```json
{
  "datos": {
    "usuario": {
      "id": 4,
      "nombre": "Lucía",
      "apellidos": "Fernández Ruiz",
      "email": "cliente@kalma.test",
      "rol": "cliente"
    },
    "csrf_token": "633264b41b…"
  }
}
```

### 8. Token CSRF falso (403)

`POST /api/logout` · permiso: Sesión

```http
POST /api/logout
X-CSRF-Token: <token de la sesión>
```

Respuesta `403 Forbidden`

```json
{
  "error": {
    "codigo": 403,
    "mensaje": "Token CSRF ausente o no válido",
    "detalles": {
      "motivo": "csrf"
    }
  }
}
```

### 9. Fuerza bruta: sexto intento bloqueado (429)

`POST /api/login` · permiso: Público

```http
POST /api/login
Content-Type: application/json
X-CSRF-Token: <token de la sesión>

{
  "email": "ataque.1795120961257@correo.test",
  "password": "Incorrecta1"
}
```

Respuesta `429 Too Many Requests`

```json
{
  "error": {
    "codigo": 429,
    "mensaje": "Demasiados intentos. Vuelve a intentarlo dentro de 15 min",
    "detalles": {
      "reintentar_en_segundos": 900
    }
  }
}
```

## Catálogo público

### 10. Búsqueda por texto y talla (200)

`GET /api/productos?buscar=dunk&talla=40` · permiso: Público

```http
GET /api/productos?buscar=dunk&talla=40
```

Respuesta `200 OK`

```json
{
  "datos": [
    {
      "id": 4,
      "nombre": "Nike Dunk Low Retro",
      "colorway": "White/Grey Fog",
      "codigo_marca": "DD1391-103",
      "slug": "nike-dunk-low-retro-dd1391-103",
      "precio": 119.99,
      "personalizable": true,
      "imagen": "/img/productos/dd1391-103.webp",
      "categoria": {
        "id": 1,
        "nombre": "Lifestyle"
      },
      "color": {
        "nombre": "Gris",
        "hex": "#9E9E9E"
      },
      "stock_total": 81,
      "tallas_disponibles": [
        39.0,
        40.0,
        "… 7 más"
      ]
    },
    {
      "id": 3,
      "nombre": "Nike Dunk Low Retro",
      "colorway": "White/Black-White",
      "codigo_marca": "DD1391-100",
      "slug": "nike-dunk-low-retro-dd1391-100",
      "precio": 119.99,
      "personalizable": true,
      "imagen": "/img/productos/dd1391-100.webp",
      "categoria": {
        "id": 1,
        "nombre": "Lifestyle"
      },
      "color": {
        "nombre": "Negro",
        "hex": "#111111"
      },
      "stock_total": 130,
      "tallas_disponibles": [
        39.0,
        40.0,
        "… 7 más"
      ]
    }
  ],
  "paginacion": {
    "pagina": 1,
    "por_pagina": 12,
    "total": 2,
    "paginas": 1
  },
  "filtros": {
    "buscar": "dunk",
    "talla": 40.0,
    "orden": "novedades"
  }
}
```

### 11. Ficha de producto (200)

`GET /api/productos/nike-air-force-1-07-cw2288-111` · permiso: Público

```http
GET /api/productos/nike-air-force-1-07-cw2288-111
```

Respuesta `200 OK`

```json
{
  "datos": {
    "id": 1,
    "nombre": "Nike Air Force 1 '07",
    "colorway": "White/White",
    "codigo_marca": "CW2288-111",
    "slug": "nike-air-force-1-07-cw2288-111",
    "precio": 119.99,
    "personalizable": true,
    "imagen": "/img/productos/cw2288-111.webp",
    "categoria": {
      "id": 1,
      "nombre": "Lifestyle"
    },
    "color": {
      "nombre": "Blanco",
      "hex": "#FFFFFF"
    },
    "stock_total": 163,
    "descripcion": "El clásico de baloncesto de 1982 en su versión más icónica: piel blanca de arriba abajo, perforaciones en la puntera y amortiguación Nike…",
    "fecha_lanzamiento": null,
    "tallas": [
      {
        "variante_id": 1,
        "talla": 39.0,
        "stock": 9,
        "disponible": true
      },
      {
        "variante_id": 2,
        "talla": 40.0,
        "stock": 15,
        "disponible": true
      },
      "… 7 más"
    ],
    "otros_colores": [
      {
        "slug": "nike-air-force-1-07-cw2288-001",
        "colorway": "Black/Black",
        "codigo_marca": "CW2288-001",
        "color": {
          "nombre": "Negro",
          "hex": "#111111"
        },
        "imagen": "/img/productos/cw2288-001.webp"
      }
    ],
    "opciones_personalizacion": [
      {
        "id": 4,
        "tipo": "bordado",
        "nombre": "Nombre bordado en el talón",
        "descripcion": "Hasta 12 letras bordadas en el talón de cada zapatilla",
        "max_caracteres": 12,
        "recargo": 15.0
      },
      {
        "id": 8,
        "tipo": "certificado",
        "nombre": "Certificado de autenticidad",
        "descripcion": "Tarjeta con el código de estilo del modelo y la etiqueta RFID de la talla",
        "max_caracteres": null,
        "recargo": 9.0
      },
      "… 5 más"
    ]
  }
}
```

### 12. Producto inexistente (404)

`GET /api/productos/no-existe` · permiso: Público

```http
GET /api/productos/no-existe
```

Respuesta `404 Not Found`

```json
{
  "error": {
    "codigo": 404,
    "mensaje": "Producto no encontrado"
  }
}
```

## Área del cliente

### 13. Crear pedido personalizado con dos líneas (201)

`POST /api/pedidos` · permiso: `pedidos.propios` (cliente)

```http
POST /api/pedidos
Content-Type: application/json
X-CSRF-Token: <token de la sesión>

{
  "notas": "Pedido de prueba desde Postman",
  "lineas": [
    {
      "variante_id": 1,
      "cantidad": 2,
      "precio_unitario": 1,
      "personalizacion": [
        {
          "opcion_id": 1
        }
      ]
    },
    {
      "variante_id": 2,
      "cantidad": 1,
      "personalizacion": [
        {
          "opcion_id": 4,
          "texto": "LUCIA"
        }
      ]
    }
  ]
}
```

Respuesta `201 Created` · `Location: /api/pedidos/SF-2026-000025`

```json
{
  "datos": {
    "id": 25,
    "codigo": "SF-2026-000025",
    "estado": "pendiente",
    "personalizado": true,
    "total": 398.97,
    "fecha_creacion": "2026-11-19T21:42:42+01:00",
    "cliente": {
      "id": 1,
      "nombre": "Lucía Fernández Ruiz",
      "email": "cliente@kalma.test"
    },
    "direccion_envio": "Calle Mayor 12, 2º A, 28013 Madrid (Madrid)",
    "codigo_seguimiento": null,
    "notas": "Pedido de prueba desde Postman",
    "fecha_actualizacion": "2026-11-19T21:42:42+01:00",
    "se_puede_cancelar": true,
    "lineas": [
      {
        "variante_id": 1,
        "producto": "Nike Air Force 1 '07",
        "color": "White/White",
        "slug": "nike-air-force-1-07-cw2288-111",
        "imagen": "/img/productos/cw2288-111.webp",
        "talla": 39.0,
        "cantidad": 2,
        "precio_unitario": 119.99,
        "recargo_personalizacion": 12.0,
        "importe": 263.98,
        "personalizacion": [
          {
            "opcion_id": 1,
            "tipo": "packaging",
            "nombre": "Caja edición KALMA",
            "texto": null,
            "recargo": 12.0
          }
        ]
      },
      {
        "variante_id": 2,
        "producto": "Nike Air Force 1 '07",
        "color": "White/White",
        "slug": "nike-air-force-1-07-cw2288-111",
        "imagen": "/img/productos/cw2288-111.webp",
        "talla": 40.0,
        "cantidad": 1,
        "precio_unitario": 119.99,
        "recargo_personalizacion": 15.0,
        "importe": 134.99,
        "personalizacion": [
          {
            "opcion_id": 4,
            "tipo": "bordado",
            "nombre": "Nombre bordado en el talón",
            "texto": "LUCIA",
            "recargo": 15.0
          }
        ]
      }
    ],
    "historial": [
      {
        "estado_anterior": null,
        "estado_nuevo": "pendiente",
        "fecha": "2026-11-19T21:42:42+01:00",
        "comentario": "Pedido creado por el cliente",
        "rol": "cliente"
      }
    ]
  }
}
```

### 14. Cantidad no válida (400)

`POST /api/pedidos` · permiso: `pedidos.propios` (cliente)

```http
POST /api/pedidos
Content-Type: application/json
X-CSRF-Token: <token de la sesión>

{
  "lineas": [
    {
      "variante_id": 1,
      "cantidad": 0
    },
    {
      "variante_id": 2,
      "cantidad": 25
    }
  ]
}
```

Respuesta `400 Bad Request`

```json
{
  "error": {
    "codigo": 400,
    "mensaje": "Hay datos no válidos",
    "detalles": {
      "errores": {
        "lineas.0.cantidad": "Número entero entre 1 y 20",
        "lineas.1.cantidad": "Número entero entre 1 y 20"
      }
    }
  }
}
```

### 15. Pedido sin stock suficiente (409)

`POST /api/pedidos` · permiso: `pedidos.propios` (cliente)

```http
POST /api/pedidos
Content-Type: application/json
X-CSRF-Token: <token de la sesión>

{
  "lineas": [
    {
      "variante_id": 1,
      "cantidad": 10
    }
  ]
}
```

Respuesta `409 Conflict`

```json
{
  "error": {
    "codigo": 409,
    "mensaje": "No hay stock suficiente para alguna de las tallas",
    "detalles": {
      "sin_stock": [
        {
          "linea": 0,
          "variante_id": 1,
          "producto": "Nike Air Force 1 '07 White/White",
          "talla": 39.0,
          "solicitado": 10,
          "disponible": 9
        }
      ]
    }
  }
}
```

### 16. Pedido de otro cliente (404, IDOR)

`GET /api/pedidos/SF-2026-000022` · permiso: `pedidos.propios` (cliente)

```http
GET /api/pedidos/SF-2026-000022
```

Respuesta `404 Not Found`

```json
{
  "error": {
    "codigo": 404,
    "mensaje": "Pedido no encontrado"
  }
}
```

### 17. Cancelar un pedido ya enviado (409)

`POST /api/pedidos/SF-2026-000013/cancelar` · permiso: `pedidos.propios` (cliente)

```http
POST /api/pedidos/SF-2026-000013/cancelar
X-CSRF-Token: <token de la sesión>
```

Respuesta `409 Conflict`

```json
{
  "error": {
    "codigo": 409,
    "mensaje": "Un pedido en estado «enviado» ya no se puede cancelar",
    "detalles": {
      "estado_actual": "enviado",
      "estados_permitidos": [
        "entregado"
      ]
    }
  }
}
```

## Usuarios sin permiso

### 18. Sin sesión (401)

`GET /api/usuarios` · permiso: `usuarios.gestionar` (admin)

```http
GET /api/usuarios
```

Respuesta `401 Unauthorized`

```json
{
  "error": {
    "codigo": 401,
    "mensaje": "Debes iniciar sesión"
  }
}
```

### 19. Cliente pide la lista de usuarios (403)

`GET /api/usuarios` · permiso: `usuarios.gestionar` (admin)

```http
GET /api/usuarios
```

Respuesta `403 Forbidden`

```json
{
  "error": {
    "codigo": 403,
    "mensaje": "No tienes permiso para esta operación"
  }
}
```

### 20. El almacén no puede confirmar el pago (403)

`PATCH /api/gestion/pedidos/SF-2026-000026/estado` · permiso: `pedidos.confirmar_cancelar` (admin)

```http
PATCH /api/gestion/pedidos/SF-2026-000026/estado
Content-Type: application/json
X-CSRF-Token: <token de la sesión>

{
  "estado": "confirmado"
}
```

Respuesta `403 Forbidden`

```json
{
  "error": {
    "codigo": 403,
    "mensaje": "Confirmar el pago o cancelar un pedido es decisión de administración"
  }
}
```

## Gestión interna

### 21. Administración confirma el pago (200)

`PATCH /api/gestion/pedidos/SF-2026-000026/estado` · permiso: `pedidos.confirmar_cancelar` (admin)

```http
PATCH /api/gestion/pedidos/SF-2026-000026/estado
Content-Type: application/json
X-CSRF-Token: <token de la sesión>

{
  "estado": "confirmado"
}
```

Respuesta `200 OK`

```json
{
  "datos": {
    "id": 26,
    "codigo": "SF-2026-000026",
    "estado": "confirmado",
    "personalizado": false,
    "total": 119.99,
    "fecha_creacion": "2026-11-19T21:42:46+01:00",
    "cliente": {
      "id": 1,
      "nombre": "Lucía Fernández Ruiz",
      "email": "cliente@kalma.test"
    },
    "direccion_envio": "Calle Mayor 12, 2º A, 28013 Madrid (Madrid)",
    "codigo_seguimiento": null,
    "notas": "Pedido de prueba de la gestión interna",
    "fecha_actualizacion": "2026-11-19T21:42:48+01:00",
    "se_puede_cancelar": true,
    "lineas": [
      {
        "variante_id": 23,
        "producto": "Nike Dunk Low Retro",
        "color": "White/Black-White",
        "slug": "nike-dunk-low-retro-dd1391-100",
        "imagen": "/img/productos/dd1391-100.webp",
        "talla": 42.0,
        "cantidad": 1,
        "precio_unitario": 119.99,
        "recargo_personalizacion": 0.0,
        "importe": 119.99,
        "personalizacion": []
      }
    ],
    "historial": [
      {
        "estado_anterior": null,
        "estado_nuevo": "pendiente",
        "fecha": "2026-11-19T21:42:46+01:00",
        "comentario": "Pedido creado por el cliente",
        "rol": "cliente",
        "usuario": "Lucía"
      },
      {
        "estado_anterior": "pendiente",
        "estado_nuevo": "confirmado",
        "fecha": "2026-11-19T21:42:48+01:00",
        "comentario": "Pago verificado",
        "rol": "admin",
        "usuario": "Laura"
      }
    ]
  }
}
```

### 22. El almacén lo entrega al transportista (200)

`PATCH /api/gestion/pedidos/SF-2026-000026/estado` · permiso: `pedidos.cambiar_estado` (almacén y admin)

```http
PATCH /api/gestion/pedidos/SF-2026-000026/estado
Content-Type: application/json
X-CSRF-Token: <token de la sesión>

{
  "estado": "enviado",
  "codigo_seguimiento": "kalma-1795120968770"
}
```

Respuesta `200 OK`

```json
{
  "datos": {
    "id": 26,
    "codigo": "SF-2026-000026",
    "estado": "enviado",
    "personalizado": false,
    "total": 119.99,
    "fecha_creacion": "2026-11-19T21:42:46+01:00",
    "cliente": {
      "id": 1,
      "nombre": "Lucía Fernández Ruiz",
      "email": "cliente@kalma.test"
    },
    "direccion_envio": "Calle Mayor 12, 2º A, 28013 Madrid (Madrid)",
    "codigo_seguimiento": "KALMA-1795120968770",
    "notas": "Pedido de prueba de la gestión interna",
    "fecha_actualizacion": "2026-11-19T21:42:48+01:00",
    "se_puede_cancelar": false,
    "lineas": [
      {
        "variante_id": 23,
        "producto": "Nike Dunk Low Retro",
        "color": "White/Black-White",
        "slug": "nike-dunk-low-retro-dd1391-100",
        "imagen": "/img/productos/dd1391-100.webp",
        "talla": 42.0,
        "cantidad": 1,
        "precio_unitario": 119.99,
        "recargo_personalizacion": 0.0,
        "importe": 119.99,
        "personalizacion": []
      }
    ],
    "historial": [
      {
        "estado_anterior": null,
        "estado_nuevo": "pendiente",
        "fecha": "2026-11-19T21:42:46+01:00",
        "comentario": "Pedido creado por el cliente",
        "rol": "cliente",
        "usuario": "Lucía"
      },
      {
        "estado_anterior": "pendiente",
        "estado_nuevo": "confirmado",
        "fecha": "2026-11-19T21:42:48+01:00",
        "comentario": "Pago verificado",
        "rol": "admin",
        "usuario": "Laura"
      },
      "… 2 más"
    ]
  }
}
```

### 23. Ajustar: un par con la caja dañada (200)

`PATCH /api/gestion/variantes/23/stock` · permiso: `stock.gestionar` (almacén y admin)

```http
PATCH /api/gestion/variantes/23/stock
Content-Type: application/json
X-CSRF-Token: <token de la sesión>

{
  "cantidad": -1,
  "motivo": "Recuento: un par con la caja dañada (prueba)"
}
```

Respuesta `200 OK`

```json
{
  "datos": {
    "variante_id": 23,
    "producto": {
      "id": 3,
      "nombre": "Nike Dunk Low Retro",
      "colorway": "White/Black-White",
      "codigo_marca": "DD1391-100",
      "slug": "nike-dunk-low-retro-dd1391-100",
      "activo": true,
      "categoria": {
        "id": 1,
        "nombre": "Lifestyle"
      },
      "fecha_lanzamiento": null,
      "lanzamiento": false
    },
    "talla": 42.0,
    "stock": 19,
    "stock_minimo": 3,
    "bajo_minimo": false,
    "rfid_tienda": "4B4C4D00000301A400000001",
    "activo": true,
    "stock_resultante": 19
  }
}
```

### 24. Cargar albarán (200)

`POST /api/gestion/albaranes?referencia=ALB-PRUEBA-ZZ7977-259` · permiso: `productos.gestionar` (admin)

```http
POST /api/gestion/albaranes?referencia=ALB-PRUEBA-ZZ7977-259
Content-Type: text/csv
X-CSRF-Token: <token de la sesión>

codigo_marca;nombre;colorway;categoria;color;precio;talla;cantidad
CW2288-111;;;;;;42;2
CW2288-111;;;;;;42;1
ZZ7977-259;;;;;;46;2
ZY3796-999;Nike Prueba Albarán;White/University Blue;Lifestyle;Azul;119,99;41;4
ZY3796-999;;;;;;41;1
```

Respuesta `200 OK`

```json
{
  "datos": {
    "referencia": "ALB-PRUEBA-ZZ7977-259",
    "filas": 5,
    "unidades": 10,
    "productos_nuevos": [
      "ZY3796-999"
    ],
    "productos_actualizados": [
      "CW2288-111",
      "ZZ7977-259"
    ],
    "tallas_nuevas": 2,
    "desactivados": [
      "ZZ7977-259"
    ],
    "tallas_desactivadas": [],
    "repetida": false,
    "motivo_repetida": null,
    "aviso": "Se ha sumado el stock de zapatillas desactivadas, pero siguen sin venderse. Para volver a venderlas: PATCH /api/gestion/productos/{id} co…"
  }
}
```

### 25. Informe: ventas por modelo (200)

`GET /api/gestion/informes/ventas-por-modelo` · permiso: `informes.ver` (admin)

```http
GET /api/gestion/informes/ventas-por-modelo
```

Respuesta `200 OK`

```json
{
  "datos": {
    "informe": "ventas-por-modelo",
    "desde": null,
    "hasta": null,
    "filas": [
      {
        "producto_id": 1,
        "nombre": "Nike Air Force 1 '07",
        "colorway": "White/White",
        "codigo_marca": "CW2288-111",
        "pedidos": 5,
        "unidades": 5,
        "ingresos": 644.95
      },
      {
        "producto_id": 3,
        "nombre": "Nike Dunk Low Retro",
        "colorway": "White/Black-White",
        "codigo_marca": "DD1391-100",
        "pedidos": 5,
        "unidades": 5,
        "ingresos": 631.95
      },
      "… 9 más"
    ]
  }
}
```

### 26. Exportar pedidos en CSV (200)

`GET /api/gestion/exportar/pedidos?formato=csv` · permiso: `datos.exportar` (admin)

```http
GET /api/gestion/exportar/pedidos?formato=csv
```

Respuesta `200 OK` · `Content-Type: text/csv; charset=utf-8`

```text
codigo;fecha;estado;cliente_email;producto;color;talla;cantidad;precio_unitario;recargo_personalizacion;importe;personalizacion;total_pedido
SF-2026-000001;2026-07-03T11:20:00+02:00;entregado;paula.romero@correo.test;"Nike Air Force 1 '07";White/White;39;1;119,99;0,00;119,99;;119,99
SF-2026-000002;2026-07-08T10:05:00+02:00;entregado;andres.lozano@correo.test;"Nike Air Force 1 '07";White/White;42;1;119,99;25,00;144,99;"Caja de coleccionista";269,98
SF-2026-000002;2026-07-08T10:05:00+02:00;entregado;andres.lozano@correo.test;"Nike Dunk Low Retro";White/Black-White;42;1;119,99;5,00;124,99;"Cordones de repuesto rojos";269,98
SF-2026-000003;2026-07-15T18:40:00+02:00;entregado;javier.ortega@correo.test;"Nike Air Max 90";"White/Black-Cool Grey-Radiant Red";42;1;149,99;0,00;149,99;;249,98
SF-2026-000003;2026-07-15T18:40:00+02:00;entregado;javier.ortega@correo.test;"Nike Cortez Leather";"White/Varsity Blue/Varsity Red";42;1;99,99;0,00;99,99;;249,98
… 29 líneas más
```

### 27. Auditoría: cambios de estado de consultas (200)

`GET /api/gestion/auditoria?tipo=consulta.cambiar_estado&por_pagina=5` · permiso: `auditoria.ver` (admin)

```http
GET /api/gestion/auditoria?tipo=consulta.cambiar_estado&por_pagina=5
```

Respuesta `200 OK`

```json
{
  "datos": [
    {
      "id": 168,
      "fecha": "2026-11-19T21:42:52+01:00",
      "usuario": {
        "id": 1,
        "email": "admin@kalma.test"
      },
      "tipo_evento": "consulta.cambiar_estado",
      "entidad": "consulta",
      "entidad_id": 4,
      "resultado": "exito",
      "detalle": {
        "a": "respondida",
        "de": "pendiente"
      },
      "ip": "172.23.0.1"
    }
  ],
  "paginacion": {
    "pagina": 1,
    "por_pagina": 5,
    "total": 1,
    "paginas": 1
  }
}
```

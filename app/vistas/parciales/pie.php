    </main>
    <footer class="pie">
        <a class="pie__marca" href="/">
            <img src="/img/logo-kalma.png" alt="KALMA SNKRS" width="640" height="275" loading="lazy">
        </a>
        <nav class="pie__enlaces" aria-label="Pie de página">
            <a href="/catalogo">Catálogo</a>
            <a href="/lanzamientos">Lanzamientos</a>
            <a href="/#personalizacion">Personalización</a>
            <a href="/sobre-nosotros">Sobre nosotros</a>
            <?php // Con sesión no tiene sentido ofrecer entrar: el pie lleva a la cuenta o al panel, como la cabecera ?>
            <?php $usuarioPie = \App\Seguridad\Sesion::usuario(); ?>
            <?php if ($usuarioPie === null): ?>
                <a href="/login">Entrar</a>
                <a href="/registro">Crear cuenta</a>
            <?php elseif (($usuarioPie['rol'] ?? '') === 'cliente'): ?>
                <a href="/cuenta">Mi cuenta</a>
            <?php else: ?>
                <a href="/panel">Panel interno</a>
            <?php endif; ?>
        </nav>
        <p class="pie__legal">© <?= date('Y') ?> KALMA SNKRS. Proyecto intermodular de 2.º de DAW.</p>
    </footer>
</body>
</html>

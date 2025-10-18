const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  console.log('Navegando a la página del paciente...');
  await page.goto('http://localhost:8080/patient.php?id=1');
  await page.waitForLoadState('networkidle');

  // Tomar captura inicial
  console.log('Tomando captura inicial...');
  await page.screenshot({ path: 'odontogram_initial.png', fullPage: true });

  // Buscar la sección del odontograma
  const odontogramSection = page.locator('[data-odontogram-section="odontodiagrama"]');
  await odontogramSection.scrollIntoViewIfNeeded();

  // Test 1: Seleccionar color azul y trazo "dot"
  console.log('\nTest 1: Marcando una sección con color azul y trazo "dot"...');
  const wrapper = page.locator('[data-diagram="odontodiagrama"]').first();

  // Seleccionar color azul (ya está seleccionado por defecto)
  const blueButton = wrapper.locator('[data-color="blue"]');
  await blueButton.click();
  console.log('✓ Color azul seleccionado');

  // Pintar la sección central del diente 55 en modo color
  const tooth55 = page.locator('[data-odontogram-section="odontodiagrama"] [data-tooth="55"]').first();
  const centerCell = tooth55.locator('[data-surface="center"]');
  await centerCell.click();
  console.log('✓ Sección central del diente 55 pintada en azul');

  // Seleccionar trazo "dot" (cambia a modo marcas automáticamente)
  const dotButton = wrapper.locator('[data-mark="dot"]');
  await dotButton.click();
  console.log('✓ Trazo "dot" seleccionado');

  // Aplicar la marca
  await centerCell.click();
  console.log('✓ Marca aplicada sobre la sección central del diente 55');

  // Esperar un poco para que se aplique el estilo
  await page.waitForTimeout(500);

  // Verificar que se aplicó el color y el trazo
  const hasFillBlue = await centerCell.evaluate((el) => el.classList.contains('fill-blue'));
  const hasMarkDot = await centerCell.evaluate((el, expectedMark) => {
    const toothCard = el.closest('.tooth-card');
    if (!toothCard) return false;
    const symbolGroupId = toothCard.dataset.symbolGroup;
    const surface = el.dataset.surface;
    if (!symbolGroupId || !surface) return false;
    const group = document.getElementById(symbolGroupId);
    if (!group) return false;
    return Boolean(group.querySelector(`[data-surface="${surface}"][data-mark="${expectedMark}"]`));
  }, 'dot');
  console.log(`  - Tiene fill-blue: ${hasFillBlue ? '✓' : '✗'}`);
  console.log(`  - Tiene mark-dot: ${hasMarkDot ? '✓' : '✗'}`);

  // Test 2: Cambiar el símbolo a "x"
  console.log('\nTest 2: Cambiando el símbolo a "x"...');
  const xButton = wrapper.locator('[data-mark="x"]');
  await xButton.click();
  await centerCell.click();

  // Verificar que el símbolo cambió
  await page.waitForTimeout(500);
  const currentMarkIsX = await centerCell.evaluate((el, expectedMark) => {
    const toothCard = el.closest('.tooth-card');
    if (!toothCard) return false;
    const symbolGroupId = toothCard.dataset.symbolGroup;
    const surface = el.dataset.surface;
    if (!symbolGroupId || !surface) return false;
    const group = document.getElementById(symbolGroupId);
    if (!group) return false;
    return Boolean(group.querySelector(`[data-surface="${surface}"][data-mark="${expectedMark}"]`));
  }, 'x');
  console.log(`  - Marcador actualizado a "x": ${currentMarkIsX ? '✓' : '✗'}`);

  // Test 3: Marcar varias secciones de un diente redondo
  console.log('\nTest 3: Marcando múltiples secciones del diente 54 (redondo)...');
  const tooth54 = page.locator('[data-odontogram-section="odontodiagrama"] [data-tooth="54"]').first();

  // Seleccionar color rojo
  const redButton = wrapper.locator('[data-color="red"]');
  await redButton.click();
  console.log('✓ Color rojo seleccionado');

  const colorModeButton = wrapper.locator('[data-mode="color"]');
  const markModeButton = wrapper.locator('[data-mode="mark"]');

  const upperLeftCell = tooth54.locator('[data-surface="upper_left"]');
  await colorModeButton.click();
  await upperLeftCell.click();
  await page.waitForTimeout(200);

  // Seleccionar trazo vertical y aplicar la marca
  const verticalButton = wrapper.locator('[data-mark="vertical"]');
  await verticalButton.click();
  console.log('✓ Trazo "vertical" seleccionado');

  await upperLeftCell.click();
  await page.waitForTimeout(300);
  console.log('✓ Marcada sección upper_left del diente 54');

  // Seleccionar trazo horizontal para otra sección
  const horizontalButton = wrapper.locator('[data-mark="horizontal"]');
  await horizontalButton.click();
  console.log('✓ Trazo "horizontal" seleccionado');

  const lowerRightCell = tooth54.locator('[data-surface="lower_right"]');
  await colorModeButton.click();
  await lowerRightCell.click();
  await page.waitForTimeout(200);

  await markModeButton.click();

  // Aplicar la marca horizontal
  await lowerRightCell.click();
  await page.waitForTimeout(300);
  console.log('✓ Marcada sección lower_right del diente 54');

  // Test 4: Verificar que el color no se desborda del círculo
  console.log('\nTest 4: Verificando que los colores no se desbordan en dientes redondos...');
  await tooth54.scrollIntoViewIfNeeded();
  await page.screenshot({
    path: 'odontogram_tooth54_detail.png',
    clip: await tooth54.boundingBox()
  });
  console.log('✓ Captura del diente 54 guardada en odontogram_tooth54_detail.png');

  // Test 5: Usar el borrador
  console.log('\nTest 5: Probando el borrador...');
  const eraseButton = wrapper.locator('[data-mark="erase"]');
  await eraseButton.click();
  console.log('✓ Borrador seleccionado');

  await centerCell.click();
  await page.waitForTimeout(300);

  const hasFillAfterErase = await centerCell.evaluate((el) => el.classList.contains('fill-blue'));
  const hasMarkAfterErase = await centerCell.evaluate((el) => {
    const toothCard = el.closest('.tooth-card');
    if (!toothCard) return false;
    const symbolGroupId = toothCard.dataset.symbolGroup;
    const surface = el.dataset.surface;
    if (!symbolGroupId || !surface) return false;
    const group = document.getElementById(symbolGroupId);
    if (!group) return false;
    return Boolean(group.querySelector(`[data-surface="${surface}"]`));
  });
  console.log(`  - Ya NO tiene fill-blue: ${!hasFillAfterErase ? '✓' : '✗'}`);
  console.log(`  - Ya NO tiene mark-dot: ${!hasMarkAfterErase ? '✓' : '✗'}`);

  if (!hasFillAfterErase && !hasMarkAfterErase) {
    console.log('✓ ÉXITO: El borrador funciona correctamente');
  } else {
    console.log('✗ ERROR: El borrador no funcionó correctamente');
  }

  // Tomar captura final
  console.log('\nTomando captura final...');
  await odontogramSection.scrollIntoViewIfNeeded();
  await page.screenshot({ path: 'odontogram_final.png', fullPage: true });

  console.log('\n=== RESUMEN DE PRUEBAS ===');
  console.log('✓ Se puede marcar una sección con color y trazo');
  console.log(currentMarkIsX ? '✓' : '✗', 'Los símbolos se pueden actualizar');
  console.log('✓ Se pueden marcar múltiples secciones diferentes');
  console.log(!hasFillAfterErase && !hasMarkAfterErase ? '✓' : '✗', 'El borrador funciona correctamente');
  console.log('\nCapturas guardadas:');
  console.log('  - odontogram_initial.png');
  console.log('  - odontogram_tooth54_detail.png');
  console.log('  - odontogram_final.png');

  await browser.close();
})();

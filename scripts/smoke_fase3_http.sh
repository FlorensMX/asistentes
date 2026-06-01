#!/usr/bin/env bash
#
# scripts/smoke_fase3_http.sh
#
# Smoke test de la CAPA HTTP de la Fase 3: que los endpoints validen CSRF,
# respondan JSON, sean idempotentes, y que el guard de SUSPENSIÓN por campaña
# (409) funcione end-to-end — incluido el caso "campaña declarada DESPUÉS de
# registrar la asistencia".
#
# A diferencia del harness PHP (que revierte todo), esto SÍ pega a los
# endpoints reales. Por eso usa:
#   - un USUARIO DE PRUEBA desechable (créalo antes; ver REQUISITOS),
#   - una fecha ~1 año en el futuro (no choca con datos reales),
#   - limpieza explícita al final (psql DELETE).
#
# REQUISITOS:
#   - curl y psql en el PATH; acceso a la BD 'asistentes'.
#   - Un asistente de prueba real. Para crearlo:
#       sudo -u www-data php scripts/crear_admin.php
#     (o desde admin_asistentes.php). El rol no importa para esta prueba.
#
# AJUSTA 2 cosas a tu login.php real (marcadas con  # <-- AJUSTA):
#   1) los nombres de campo del formulario de login (asumo usuario/password/csrf),
#   2) cómo se extrae el token CSRF de la página (asumo input name="csrf").
#
# USO:
#   BASE_URL="https://montesion.cloud/apps/asistentes" \
#   TEST_USER="smoke_tester" TEST_PASS="..." \
#   PSQL="psql -U asi_app -d asistentes" \
#   bash scripts/smoke_fase3_http.sh
#
# Sólo 'set -u' (sin '-e'/'pipefail'): así un grep que NO encuentra el token
# CSRF no mata el script en silencio, sino que cae al diagnóstico de abajo.
set -u

BASE_URL="${BASE_URL:?define BASE_URL (p.ej. https://montesion.cloud/apps/asistentes)}"
TEST_USER="${TEST_USER:?define TEST_USER (asistente de prueba)}"
TEST_PASS="${TEST_PASS:?define TEST_PASS}"
PSQL="${PSQL:-psql -U asi_app -d asistentes}"

JAR="$(mktemp)"; trap 'rm -f "$JAR"' EXIT
pass=0; fail=0
ok() { echo "[OK]    $1"; pass=$((pass+1)); }
ko() { echo "[FALLA] $1"; fail=$((fail+1)); }
q()  { $PSQL -tAc "$1"; }   # consulta escalar

echo "== Resolviendo datos contra la BD =="
AID="$(q "SELECT id FROM asistentes WHERE usuario = '${TEST_USER}'")"
[ -n "$AID" ] || { echo "No existe el asistente de prueba '${TEST_USER}'. Créalo primero."; exit 2; }
CULTO="$(q "SELECT id FROM cultos WHERE nombre = 'Miércoles' AND activo LIMIT 1")"
[ -n "$CULTO" ] || { echo "No hay culto 'Miércoles' activo (¿seed 002?)."; exit 2; }
# Próximo miércoles ~1 año adelante. DOW de Postgres: 0=domingo..6=sábado (= dia_semana).
FECHA="$(q "SELECT (CURRENT_DATE + ((7 + 3 - EXTRACT(DOW FROM CURRENT_DATE)::int) % 7) + 364)::date")"
CINI="$(q "SELECT '${FECHA}'::date - 1")"; CFIN="$(q "SELECT '${FECHA}'::date + 1")"
echo "usuario=${TEST_USER}(id=${AID})  culto=${CULTO}  fecha=${FECHA}"

echo "== Login =="
LOGIN_HTML="$(curl -s -c "$JAR" "${BASE_URL}/login.php")"

# Extrae el token CSRF del login probando los patrones más comunes (input
# hidden y <meta>, varios nombres de campo). Sin set -e, un no-match queda
# vacío y NO aborta.
csrf_from() { printf '%s' "$1" | grep -oiE "$2" | head -1 | sed -E 's/.*(value|content)="//; s/".*//'; }
CSRF_LOGIN="$(csrf_from "$LOGIN_HTML" 'name="(csrf|csrf_token|_csrf|csrf-token)"[^>]*value="[^"]+"')"
[ -n "$CSRF_LOGIN" ] || CSRF_LOGIN="$(csrf_from "$LOGIN_HTML" 'value="[^"]+"[^>]*name="(csrf|csrf_token|_csrf)"')"
[ -n "$CSRF_LOGIN" ] || CSRF_LOGIN="$(csrf_from "$LOGIN_HTML" 'name="csrf-token"[^>]*content="[^"]+"')"

curl -s -b "$JAR" -c "$JAR" \
  --data-urlencode "usuario=${TEST_USER}" \
  --data-urlencode "password=${TEST_PASS}" \
  --data-urlencode "csrf=${CSRF_LOGIN}" \
  "${BASE_URL}/login.php" -o /dev/null            # <-- AJUSTA nombres usuario/password si tu form usa otros

# Token CSRF de sesión para las APIs (del <meta> de una página autenticada).
SEM_HTML="$(curl -s -b "$JAR" "${BASE_URL}/semana.php")"
CSRF="$(printf '%s' "$SEM_HTML" | grep -oiE 'name="csrf-token"[^>]*content="[^"]+"' | head -1 | sed -E 's/.*content="//; s/".*//')"

if [ -z "$CSRF" ]; then
  ko "No obtuve sesión autenticada."
  echo "   ---- token CSRF leído del login: '${CSRF_LOGIN:-(vacío)}'"
  echo "   ---- ¿login.php trae algo con 'csrf'? (primeras coincidencias):"
  printf '%s' "$LOGIN_HTML" | grep -ioE '.{0,40}csrf.{0,60}' | head -3 || true
  echo "   ---- ¿semana.php nos devolvió la página de login? (= el login falló):"
  printf '%s' "$SEM_HTML" | grep -ioE '(type="password"|name="usuario"|iniciar sesión)' | head -3 || true
  echo "   Ajusta los campos/patrón de arriba, o pégame estas líneas y lo afino."
  echo; echo "RESULTADO HTTP: $pass OK / $fail fallas"; exit 1
fi
ok "Login + CSRF de sesión obtenidos"

# api <endpoint> <flags curl...>  -> imprime cuerpo; deja status en $HTTP
api() {
  local ep="$1"; shift
  local out; out="$(curl -s -b "$JAR" -w $'\n%{http_code}' "$@" "${BASE_URL}/api/${ep}")"
  HTTP="${out##*$'\n'}"; printf '%s' "${out%$'\n'*}"
}

echo "== Confirmar culto (idempotente) =="
B="$(api guardar_funcion_culto.php --data-urlencode "csrf=${CSRF}" --data-urlencode "culto_id=${CULTO}" --data-urlencode "fecha=${FECHA}" --data-urlencode "asistio=1")"
{ [ "$HTTP" = "200" ] && echo "$B" | grep -q '"ok":true'; } && ok "confirmar culto => 200 ok:true" || ko "confirmar culto (HTTP=$HTTP body=$B)"
B="$(api guardar_funcion_culto.php --data-urlencode "csrf=${CSRF}" --data-urlencode "culto_id=${CULTO}" --data-urlencode "fecha=${FECHA}" --data-urlencode "asistio=1")"
{ [ "$HTTP" = "200" ] && echo "$B" | grep -q '"ok":true'; } && ok "reenvío idéntico => sigue ok (idempotente)" || ko "reenvío idéntico (HTTP=$HTTP body=$B)"

echo "== CSRF inválido debe rechazarse =="
B="$(api guardar_funcion_culto.php --data-urlencode "csrf=BASURA" --data-urlencode "culto_id=${CULTO}" --data-urlencode "fecha=${FECHA}" --data-urlencode "asistio=1")"
{ [ "$HTTP" != "200" ] || ! echo "$B" | grep -q '"ok":true'; } && ok "CSRF inválido rechazado (HTTP=$HTTP)" || ko "CSRF inválido fue ACEPTADO (HTTP=$HTTP body=$B)"

echo "== Suspensión por campaña (guard 409) =="
# La asistencia ya quedó dentro del rango que declaramos ahora: caso real
# 'campaña declarada DESPUÉS de registrar'.
q "INSERT INTO periodos_campania (asistente_id, fecha_inicio, fecha_fin, lugar, descripcion)
   VALUES (${AID}, '${CINI}', '${CFIN}', 'SMOKE', 'http test')" >/dev/null
B="$(api guardar_funcion_culto.php --data-urlencode "csrf=${CSRF}" --data-urlencode "culto_id=${CULTO}" --data-urlencode "fecha=${FECHA}" --data-urlencode "asistio=1")"
{ [ "$HTTP" = "409" ] || echo "$B" | grep -q '"ok":false'; } && ok "registrar en día de campaña => bloqueado (HTTP=$HTTP)" || ko "el guard de campaña NO bloqueó (HTTP=$HTTP body=$B)"

echo "== Desmarcar SÍ se permite aun en campaña (limpieza) =="
B="$(api guardar_funcion_culto.php --data-urlencode "csrf=${CSRF}" --data-urlencode "culto_id=${CULTO}" --data-urlencode "fecha=${FECHA}" --data-urlencode "asistio=0")"
{ [ "$HTTP" = "200" ] && echo "$B" | grep -q '"ok":true'; } && ok "desmarcar en campaña => ok (asimetría correcta)" || ko "desmarcar en campaña (HTTP=$HTTP body=$B)"

echo "== Limpieza =="
q "DELETE FROM periodos_campania WHERE asistente_id = ${AID} AND lugar = 'SMOKE'" >/dev/null
q "DELETE FROM asistencia_culto  WHERE asistente_id = ${AID} AND fecha = '${FECHA}'" >/dev/null
LEFT="$(q "SELECT (SELECT count(*) FROM periodos_campania WHERE asistente_id=${AID} AND lugar='SMOKE')
              + (SELECT count(*) FROM asistencia_culto  WHERE asistente_id=${AID} AND fecha='${FECHA}')")"
[ "$LEFT" = "0" ] && ok "sin residuos de prueba" || ko "quedaron ${LEFT} filas de prueba (revisa manualmente)"

echo; echo "RESULTADO HTTP: $pass OK / $fail fallas"
[ "$fail" -eq 0 ]

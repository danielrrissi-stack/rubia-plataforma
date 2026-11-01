# Pendências menores da Fase 1 (não bloqueiam produção)

Lista registrada pelas revisões por tarefa; triadas na revisão final como 'podem esperar'. Itens já corrigidos na rodada final foram removidos.

- Task 0: minor (deferred): SmokeTest has no behavioral Config::get test
- Task 0: minor (deferred): Config silently returns [] when config file missing (fail loudly outside tests?)
- Task 0: minor (deferred): e(?string) TypeError on int/float under strict_types
- Task 0: minor (deferred): tools/env.sh hardcodes machine paths (dev-only)
- Task 1: minor (deferred): Migrator partial failure leaves unrecorded DDL (MySQL can't roll back DDL)
- Task 1: minor (deferred): MigratorTest hardcodes Tables_in_rubia_test column name
- Task 1: minor (deferred): Database sets session time_zone -03:00 (ok: Brazil no DST since 2019) — app must not assume UTC
- Task 1: minor (deferred): insert() with empty row / int keys
- Task 1: minor (deferred): makePatient creates extra medico when only one of the ids passed
- Task 2: minor (deferred): App log line injectable via %0A in path (strip CR/LF)
- Task 2: minor (deferred): HTTPS detection duplicated; Session trusts X-Forwarded-Proto
- Task 2: minor (deferred): .htaccess localhost regex prefix match; redirect uses raw HTTP_HOST
- Task 2: minor (deferred): fmt_date accepts overflow dates; HEAD→405; trailing slash 404
- Task 3: minor (deferred): Mailer doesn't validate to-address; column length truncation edge
- Task 3: minor (deferred): AuditLog json_encode false on invalid UTF-8 (use JSON_INVALID_UTF8_SUBSTITUTE)
- Task 3: minor (deferred): no test for mail failure path
- Task 4: minor (deferred): dummy hash fixed at cost 10 / early returns skip hashing (timing enumeration; matters on PHP 8.4)
- Task 4: minor (deferred): no password_needs_rehash
- Task 4: minor (deferred): reset doesn't invalidate other tokens / inactive user can set password / no throttle
- Task 4: minor (deferred): Referrer-Policy no-referrer on reset page (global header is same-origin)
- Task 4: minor (deferred): unused Session ctor arg in AuthService
- Task 5: minor (deferred): no TOTP replay protection (RFC 6238 §5.2)
- Task 5: minor (deferred): totp_secret plaintext
- Task 5: minor (deferred): script-tag test regex weak; Base32 decode lax lengths
- Task 6: minor (deferred): is_admin extras apply to family roles too (no UI can set that; consider restricting to staff)
- Task 6: minor (deferred): guardian scope ignores ended_at (add AND g.ended_at IS NULL)
- Task 7: minor (deferred): no install token (README: install right after upload); .htaccess Apache 2.4 only; missing CSRF/installed-POST tests; convoluted ternary in InstallerTest
- Task 7: minor (deferred): logError literal newline style; $installerFactory public static test hook (mark @internal)
- Task 8: minor (deferred): no last-admin guard when demoting another admin
- Task 8: minor (deferred): email change on pending invite doesn't re-issue invite
- Task 8: minor (deferred): missing tests (edit to papel=paciente rejected; non-admin medico POST 403)
- Task 9: minor (deferred): regex $ vs \z; misleading "senha incorreta" for wrong key file; restore.php prompt nits; missing 403 tests for gerar/chave-privada
- Task 10: minor (deferred): removing already-inactive link shows success; adult patient email owned by responsavel → generic error (product decision); consent double-submit duplicates (no unique key)
- Task 10: minor (deferred): search($byCpf=true) default fail-open for future callers
- Task 11: minor (deferred): majority checks outside txn (race → 500); revoke no revoked_at guard; missing 403/404 tests; transferir w/ existing login flash wording
- Task 12: minor (deferred): -0.7 deitado correction also applied in who2007 range (beyond WHO; harmless)
- Task 12: minor (deferred): status uses z rounded 2dp (WHO-consistent; document); importer loh header name unchecked; precision comment; exact L==0 compare
- Task 13: minor (deferred): SeedLoader silently succeeds if no seed files; classifyChild doesn't validate sex/negative BP for 13-17; whrRisk rounding doc; CSV hash vs CRLF (.gitattributes -text); Container::bp() static redundancy
- Task 15: minor (deferred): legend filter no-op in growth-chart.js
- Task 14: minor (deferred): stored factor/height precision vs computed (round before compute); concurrent saves may leave 2 open goals; no override flag in audit; "Outro" preselect
- Task 16: minor (deferred): reviseForm opens for superseded/deleted entries; gestacional revisable after adulthood; array POST → "Array"; intIn rounds before int check; history kinds can get 2 current rows via direct POST; view logged before edit authorize
- Task 16: minor (deferred): missing IDOR/confidentiality tests on entry routes, extra-key drop, audit payload on update
- Task 17: minor (deferred): other sessions not invalidated on password change; same-password not rejected; manifest root-absolute paths

## Decisão pendente
- Nutricionista vê pressão arterial e classificação na tela de medidas (plano: PA em ANTROPOMETRIA; spec: nutricionista sem dados clínicos) — aguardando decisão da Dra. Rubia.

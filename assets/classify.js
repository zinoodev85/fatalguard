/**
 * Pure log-line helpers, shared by the overlay (browser global
 * window.fatalguardClassify) and the jest unit test (require()). No DOM, no deps.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory()
  } else {
    root.fatalguardClassify = factory()
  }
})(typeof self !== 'undefined' ? self : this, function () {
  // Severity bucket for a raw log line, by first match (most → least severe).
  function severity(line) {
    var t = String(line || '').toLowerCase()
    if (t.indexOf('fatal error') !== -1 || t.indexOf('uncaught') !== -1) return 'fatal'
    if (t.indexOf('parse error') !== -1 || t.indexOf('php error') !== -1 || t.indexOf('exception') !== -1) return 'error'
    if (t.indexOf('warning') !== -1) return 'warning'
    if (t.indexOf('deprecated') !== -1 || t.indexOf('notice') !== -1) return 'notice'
    return ''
  }

  // Severity for a PHP error_get_last() type code (used for captured fatals).
  function severityForType(type) {
    var n = Number(type) || 0
    if (n === 1 || n === 16 || n === 64 || n === 256) return 'fatal' // E_ERROR/E_CORE_ERROR/E_COMPILE_ERROR/E_USER_ERROR
    if (n === 4) return 'fatal' // E_PARSE
    if (n === 2 || n === 512) return 'warning'
    return 'error'
  }

  return {severity: severity, severityForType: severityForType}
})

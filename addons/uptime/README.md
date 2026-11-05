# Uptime

- Uses the Laravel HTTP client with Oh Dear HTTP uptime settings. Frequency and failure threshold belong to the core check columns, not addon config.
- Runs from the local probe. `location` is compatibility metadata; there is no secondary-location warning. Slowness warnings belong to performance.
- Records succeeded or failed observations. Connection failures and redirect limits are target failures; unexpected exceptions reach the engine.
- Sums phase durations across redirect hops and retains the final hop's original cURL statistics. Missing timings remain null; an observed zero handshake means no TLS handshake occurred.
- Evaluates body assertions only for valid JSON smaller than 2 MiB, as required by Oh Dear. Evidence contains status and issue codes, not response bodies or secret headers.

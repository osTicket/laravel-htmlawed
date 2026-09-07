# Security Policy

Please report suspected vulnerabilities privately through GitHub's security
advisory interface for this repository. Do not open a public issue containing
an exploit, bypass, or unpatched security detail.

Reports should include the affected version, policy configuration, input HTML,
observed output, expected behavior, and any known impact. Sanitizer behavior
also depends on the configured policy, so a policy that explicitly permits an
unsafe element or attribute is not necessarily a package vulnerability.

Sanitized values are HTML body fragments. Consumers must not reuse them in
HTML attributes, URLs, JavaScript, CSS, or other executable contexts. Report a
case where output escapes a correctly configured body-fragment policy as a
potential vulnerability.

The package intentionally replaces failures raised inside htmLawed or a tag
hook without preserving the original throwable. htmLawed does not mark its
input as sensitive, so retaining that trace could disclose confidential HTML
to exception reporters or logs. Reports should reproduce failures using
synthetic content rather than production data.

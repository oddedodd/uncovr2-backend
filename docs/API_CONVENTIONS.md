# Uncovr API conventions

All public endpoints are versioned below `/api/v1` and return JSON.

## Successful responses

Successful responses wrap their result in `data`:

```json
{
  "data": {
    "id": "example"
  }
}
```

List responses may include an optional `meta` object for pagination and other
response-level information:

```json
{
  "data": [],
  "meta": {
    "next_cursor": null
  }
}
```

## Error responses

Errors wrap a stable machine-readable code and a safe human-readable message
in `error`:

```json
{
  "error": {
    "code": "not_found",
    "message": "The requested resource was not found."
  }
}
```

Callers must use `error.code` for application decisions. Error messages may be
shown to users but must not be parsed or used as identifiers. Internal
exception messages, stack traces and credentials are never returned.

## Validation errors

Validation failures use HTTP 422 and include errors grouped by input field:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The submitted data is invalid.",
    "details": {
      "fields": {
        "email": [
          "The email field is required."
        ]
      }
    }
  }
}
```

## Request correlation and logs

Every API response includes an `X-Request-ID` header containing a UUID. Clients
and trusted proxies may provide their own UUID in the same request header;
invalid values are replaced. Include this ID when reporting an API problem.

Application logs are JSON and attach `request_id` to every entry written while
an API request is handled. One completion event is logged with the HTTP method,
path, named route, status code and duration in milliseconds. Query strings and
request bodies are not included in completion logs.

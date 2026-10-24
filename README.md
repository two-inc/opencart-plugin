# Two for OpenCart

The Two payment extension for OpenCart 4.1: pay by invoice for business buyers.

**Status: being rewritten.** This branch holds the new OpenCart 4.1 foundation. It installs and appears under Extensions > Payments, but does not offer Two at checkout yet. The 1.x releases for OpenCart 3 call API hosts that no longer exist, so they cannot reach Two.

## Requirements

- OpenCart 4.1.0.0 or later
- PHP 8.1 or later

## Install

Upload `two.ocmod.zip` in Extensions > Installer, install it, then install and enable Two in Extensions > Extensions > Payments.

## Development

Needs Docker and PHP 8.1+ on the host.

```sh
cp .env.example .env   # optional: API key and environment
make install           # OpenCart 4.1.0.4 on http://localhost:1238/ with the extension enabled
make test              # offline test suite
make phpstan           # static analysis
make archive           # dist/two.ocmod.zip
```

`make help` lists every target. See [AGENTS.md](AGENTS.md) for the layout and conventions.

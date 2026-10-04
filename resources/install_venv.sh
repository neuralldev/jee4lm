#!/bin/bash
# Create the plugin's dedicated Python virtualenv (resources/python_venv)
# with a pinned Python version, then install requirements.txt into it.
# Called by Jeedom as "post-install" script (see plugin_info/packages.json).
set -e

PYTHON_VERSION="3.13"
DIR="$(cd "$(dirname "$0")" && pwd)"
VENV="$DIR/python_venv"
UV_DIR="$DIR/.uv"

echo "*** jee4lm5: creating virtualenv (Python $PYTHON_VERSION) in $VENV"
rm -rf "$VENV"

if command -v "python$PYTHON_VERSION" >/dev/null 2>&1 \
   && "python$PYTHON_VERSION" -m venv "$VENV" 2>/dev/null; then
  echo "*** using system python$PYTHON_VERSION"
else
  # System has no usable Python $PYTHON_VERSION: fetch a standalone build via uv
  rm -rf "$VENV"
  echo "*** python$PYTHON_VERSION not available, bootstrapping with uv"
  mkdir -p "$UV_DIR"
  curl -LsSf https://astral.sh/uv/install.sh \
    | env UV_UNMANAGED_INSTALL="$UV_DIR" sh
  export UV_PYTHON_INSTALL_DIR="$DIR/.python"
  "$UV_DIR/uv" venv --python "$PYTHON_VERSION" --managed-python "$VENV"
fi

"$VENV/bin/python3" -m ensurepip --upgrade >/dev/null 2>&1 || true
"$VENV/bin/python3" -m pip install --upgrade pip wheel 2>/dev/null \
  || "$UV_DIR/uv" pip install --python "$VENV/bin/python3" --upgrade pip wheel
if "$VENV/bin/python3" -m pip --version >/dev/null 2>&1; then
  "$VENV/bin/python3" -m pip install -r "$DIR/requirements.txt"
else
  "$UV_DIR/uv" pip install --python "$VENV/bin/python3" -r "$DIR/requirements.txt"
fi

chown -R www-data:www-data "$VENV" "$UV_DIR" "$DIR/.python" 2>/dev/null || true
echo "*** jee4lm5: virtualenv ready: $("$VENV/bin/python3" --version)"

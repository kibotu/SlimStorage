#!/bin/bash
#
# FTP Deployment Script
# Deploys the public directory to the remote server via FTP
# Usage: ./scripts/deploy.sh
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
SECRETS_FILE="$PROJECT_ROOT/.secrets.yml"

echo "🚀 Starting deployment..."
echo ""

# Check if secrets file exists
if [ ! -f "$SECRETS_FILE" ]; then
    echo "❌ Error: .secrets.yml not found at $SECRETS_FILE"
    exit 1
fi

# Parse YAML file (simple parsing for our specific format)
parse_yaml() {
    local prefix=$1
    local s='[[:space:]]*'
    local w='[a-zA-Z0-9_]*'
    local fs=$(echo @|tr @ '\034')
    sed -ne "s|^\($s\):|\1|" \
         -e "s|^\($s\)\($w\)$s:$s[\"']\(.*\)[\"']$s\$|\1$fs\2$fs\3|p" \
         -e "s|^\($s\)\($w\)$s:$s\(.*\)$s\$|\1$fs\2$fs\3|p" "$SECRETS_FILE" |
    awk -F$fs '{
        indent = length($1)/2;
        vname[indent] = $2;
        for (i in vname) {if (i > indent) {delete vname[i]}}
        if (length($3) > 0) {
            vn=""; for (i=0; i<indent; i++) {vn=(vn)(vname[i])("_")}
            printf("%s%s=\"%s\"\n", "'$prefix'",vn $2, $3);
        }
    }'
}

# Load configuration
eval $(parse_yaml)

FTP_USER="${ftp_user}"
FTP_PASSWORD="${ftp_password}"
FTP_HOST=$(echo "${ftp_protocol}" | sed 's|ftp://||')
REMOTE_PATH="${domain_public_folder}"

if [ -z "$FTP_USER" ] || [ -z "$FTP_PASSWORD" ] || [ -z "$FTP_HOST" ] || [ -z "$REMOTE_PATH" ]; then
    echo "❌ Error: Missing FTP configuration in .secrets.yml"
    echo "   Required: ftp.user, ftp.password, ftp.protocol, domain.public_folder"
    exit 1
fi

echo "📋 Configuration:"
echo "   FTP Host: $FTP_HOST"
echo "   FTP User: $FTP_USER"
echo "   Remote Path: $REMOTE_PATH"
echo ""

# Check if lftp is installed
if ! command -v lftp &> /dev/null; then
    echo "❌ Error: lftp is not installed"
    echo "   Install with: brew install lftp (macOS) or apt-get install lftp (Linux)"
    exit 1
fi

# Create temporary lftp script
LFTP_SCRIPT=$(mktemp)
trap "rm -f $LFTP_SCRIPT" EXIT

cat > "$LFTP_SCRIPT" <<EOF
set ftp:ssl-allow no
set ssl:verify-certificate no
open -u $FTP_USER,$FTP_PASSWORD $FTP_HOST

# Upload .secrets.yml to parent directory of public folder
cd $(dirname $REMOTE_PATH)
lcd $PROJECT_ROOT
put .secrets.yml

# Upload public directory contents
cd $REMOTE_PATH
lcd $PROJECT_ROOT/public
mirror --reverse --delete --verbose --exclude-glob .DS_Store --exclude-glob .git* --exclude-glob *.log
bye
EOF

echo "📤 Uploading files to $FTP_HOST..."
echo ""

# Execute lftp
if lftp -f "$LFTP_SCRIPT"; then
    echo ""
    echo "✅ Deployment completed successfully!"
    echo ""
    echo "🌐 Your API is now live at: https://${domain_name}"
    echo ""
else
    echo ""
    echo "❌ Deployment failed!"
    exit 1
fi


#!/bin/bash

# Define output name
OUTPUT="BMS_Deploy.zip"

# Create a temporary directory for staging
mkdir -p deploy_stage

# Copy files
echo "Copying Frontend..."
cp -r public/* deploy_stage/

echo "Copying Backend..."
mkdir -p deploy_stage/backend
cp -r backend/* deploy_stage/backend/

echo "Copying Vendor (Dependencies)..."
# Check if vendor exists, if not, user might need to run composer install
if [ -d "vendor" ]; then
    cp -r vendor deploy_stage/
else
    echo "WARNING: vendor directory not found. You may need to run 'composer install' locally or on the server."
fi

echo "Copying Config..."
cp .env.example deploy_stage/.env
cp bms_db_production.sql deploy_stage/

# Create Zip
echo "Zipping..."
cd deploy_stage
zip -r ../$OUTPUT .
cd ..

# Cleanup
rm -rf deploy_stage

echo "✅ Deployment package created: $OUTPUT"
echo "👉 Upload this file to your public_html folder."

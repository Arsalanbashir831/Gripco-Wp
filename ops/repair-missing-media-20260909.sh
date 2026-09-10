#!/bin/sh
set -eu

# Reconstruct missing original media from the largest surviving WordPress
# derivative. These files are byte-for-byte copies under the URLs stored in the
# production database; no image content is transformed.
restore() {
    source_file=$1
    destination=$2

    if [ ! -f "$source_file" ]; then
        echo "Missing repair source: $source_file" >&2
        exit 1
    fi

    if [ ! -f "$destination" ]; then
        cp "$source_file" "$destination"
        chmod 0644 "$destination"
    fi
}

restore wp-content/uploads/2025/05/GRIPCO-Secures-Strategic-Partnership-with-SABIC-2000x1125.webp wp-content/uploads/2025/05/GRIPCO-Secures-Strategic-Partnership-with-SABIC.webp
restore wp-content/uploads/2025/06/IAS-Accreditation-1989x1543.webp wp-content/uploads/2025/06/IAS-Accreditation.webp
restore wp-content/uploads/2025/06/JGC-Approved-Gripco-Services-1989x1543.webp wp-content/uploads/2025/06/JGC-Approved-Gripco-Services.webp
restore wp-content/uploads/2025/08/Industrial-Chemicals-in-Welding-1-2000x1125.webp wp-content/uploads/2025/08/Industrial-Chemicals-in-Welding-1.webp
restore wp-content/uploads/2025/08/Industrial-Chemicals-in-Welding-1-2000x1125.webp wp-content/uploads/2025/08/Types-of-Industrial-Chemicals-and-Their-Applications.webp
restore wp-content/uploads/2025/08/Industrial-Chemicals-in-Welding-1-1024x768.webp wp-content/uploads/2025/08/Welding-Inspection-testing-in-Industrial-Chemistry-1024x1024.webp
restore wp-content/uploads/2025/08/Industrial-Chemicals-in-Welding-1-2000x1125.webp wp-content/uploads/2025/08/What-are-Industrial-Chemicals.webp
restore wp-content/uploads/2025/08/What-is-Water-Quality-Testing-2000x1125.webp wp-content/uploads/2025/08/What-is-Water-Quality-Testing.webp
restore wp-content/uploads/2025/09/Construction-Monitoring-and-EMC-Testing-2000x1125.webp wp-content/uploads/2025/09/Construction-Monitoring-and-EMC-Testing.webp
restore wp-content/uploads/2025/09/Construction-Monitoring-Services-2000x1125.webp wp-content/uploads/2025/09/Construction-Monitoring-Services.webp

# Forminator's form markup still references the previous generated hash.
mkdir -p wp-content/uploads/forminator/1411_6f422e19fde270613d9baf658aef0ed3/css
restore wp-content/uploads/forminator/1411_27898e0e0d2d7e632d3f2b25497acf95/css/style-1411.css wp-content/uploads/forminator/1411_6f422e19fde270613d9baf658aef0ed3/css/style-1411.css

echo "Missing production media restored."

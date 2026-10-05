-- One database per implementation, so three apps creating the contract schema on boot do not race one
-- another on shared tables. All owned by the trusted_attestation user the container already created.
CREATE DATABASE trusted_attestation_dotnet OWNER trusted_attestation;
CREATE DATABASE trusted_attestation_java OWNER trusted_attestation;
CREATE DATABASE trusted_attestation_php OWNER trusted_attestation;

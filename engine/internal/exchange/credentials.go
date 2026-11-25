package exchange

import (
	"context"
	"fmt"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"github.com/johnson407/trading-bot/engine/internal/credentials"
)

// CredentialLoader decrypts a bot's exchange credential in memory. The key is
// the Laravel APP_KEY; previous keys are tried in order. Nothing is cached to
// disk and no value is logged.
type CredentialLoader struct {
	Pool         *pgxpool.Pool
	Key          string
	PreviousKeys []string
}

// Load reads and decrypts the credential linked to a bot.
func (l CredentialLoader) Load(ctx context.Context, botID int64) (Credential, error) {
	if l.Pool == nil {
		return Credential{}, fmt.Errorf("credential loader has no database pool")
	}

	var apiKey string
	var apiSecret string
	var passphrase *string
	var testnet bool

	err := l.Pool.QueryRow(ctx,
		`SELECT c.api_key, c.api_secret, c.passphrase, c.testnet
		 FROM exchange_credentials c
		 JOIN bots b ON b.exchange_credential_id = c.id
		 WHERE b.id = $1`,
		botID,
	).Scan(&apiKey, &apiSecret, &passphrase, &testnet)
	if err != nil {
		if err == pgx.ErrNoRows {
			return Credential{}, fmt.Errorf("no exchange credential is linked to bot %d", botID)
		}

		return Credential{}, fmt.Errorf("load exchange credential: %w", err)
	}

	encryptedPassphrase := ""
	if passphrase != nil {
		encryptedPassphrase = *passphrase
	}

	credential, err := DecryptCredential(apiKey, apiSecret, encryptedPassphrase, l.Key, l.PreviousKeys...)
	if err != nil {
		return Credential{}, err
	}

	credential.Testnet = testnet

	return credential, nil
}

// DecryptCredential decrypts the three Laravel `encrypted` columns. Errors are
// passed through credentials.SafeError so no ciphertext or key reaches a log.
func DecryptCredential(apiKey, apiSecret, passphrase, key string, previousKeys ...string) (Credential, error) {
	decrypt := func(value string) (string, error) {
		if value == "" {
			return "", nil
		}

		plain, err := credentials.DecryptString(value, key, previousKeys...)
		if err != nil {
			return "", fmt.Errorf("decrypt exchange credential: %s", credentials.SafeError(err, key))
		}

		return plain, nil
	}

	keyPlain, err := decrypt(apiKey)
	if err != nil {
		return Credential{}, err
	}

	secretPlain, err := decrypt(apiSecret)
	if err != nil {
		return Credential{}, err
	}

	passphrasePlain, err := decrypt(passphrase)
	if err != nil {
		return Credential{}, err
	}

	return Credential{APIKey: keyPlain, APISecret: secretPlain, Passphrase: passphrasePlain}, nil
}

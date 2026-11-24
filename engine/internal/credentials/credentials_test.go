package credentials_test

import (
	"reflect"
	"strings"
	"testing"

	"github.com/johnson407/trading-bot/engine/internal/credentials"
	"github.com/johnson407/trading-bot/engine/internal/goldenfixture"
)

type credentialsFixture struct {
	Metadata struct {
		Format     string `json:"format"`
		Cipher     string `json:"cipher"`
		TestKey    string `json:"test_key"`
		PrevKey    string `json:"test_previous_key"`
		PHPEncoded bool   `json:"php_serialized"`
	} `json:"metadata"`
	Cases []struct {
		Label                  string   `json:"label"`
		Plaintext              string   `json:"plaintext"`
		Ciphertext             string   `json:"ciphertext"`
		PayloadKeys            []string `json:"payload_keys"`
		DecryptStringRoundTrip string   `json:"decrypt_string_round_trip"`
		PHPSerializePrefix     bool     `json:"starts_with_php_serialize_prefix"`
	} `json:"cases"`
	Rotation struct {
		Label                  string `json:"label"`
		Plaintext              string `json:"plaintext"`
		Ciphertext             string `json:"encrypted_with_previous_key"`
		DecryptWithCurrentOnly struct {
			Exception string `json:"exception"`
			Message   string `json:"message"`
		} `json:"decrypt_with_current_key_only"`
		DecryptWithBothKeys string `json:"decrypt_with_current_and_previous_keys"`
	} `json:"rotation"`
}

func loadCredentialsFixture(t *testing.T) credentialsFixture {
	t.Helper()

	var fixture credentialsFixture
	if err := goldenfixture.Load("credentials_ciphertext", &fixture); err != nil {
		t.Fatalf("load credentials fixture: %v", err)
	}

	if fixture.Metadata.Format != "php-golden-credentials/1" {
		t.Fatalf("unexpected fixture format %q", fixture.Metadata.Format)
	}

	if fixture.Metadata.Cipher != "AES-256-CBC" {
		t.Fatalf("unexpected fixture cipher %q", fixture.Metadata.Cipher)
	}

	if fixture.Metadata.PHPEncoded {
		t.Fatal("fixture unexpectedly uses PHP serialization")
	}

	return fixture
}

func TestDecryptStringGolden(t *testing.T) {
	fixture := loadCredentialsFixture(t)

	for _, testCase := range fixture.Cases {
		t.Run(testCase.Label, func(t *testing.T) {
			if len(testCase.PayloadKeys) != 4 {
				t.Fatalf("payload keys = %v, want iv/value/mac/tag", testCase.PayloadKeys)
			}

			if testCase.PHPSerializePrefix {
				t.Fatal("fixture ciphertext is PHP serialized; the Go path is decryptString only")
			}

			plaintext, err := credentials.DecryptString(testCase.Ciphertext, fixture.Metadata.TestKey)
			if err != nil {
				t.Fatalf("decrypt %s: %v", testCase.Label, err)
			}

			if plaintext != testCase.Plaintext {
				t.Fatalf("plaintext = %q, want %q", plaintext, testCase.Plaintext)
			}

			if plaintext != testCase.DecryptStringRoundTrip {
				t.Fatalf("round trip = %q, want %q", plaintext, testCase.DecryptStringRoundTrip)
			}

			if strings.HasPrefix(plaintext, "s:") || strings.HasPrefix(plaintext, "a:") {
				t.Fatalf("decrypted value looks PHP serialized: %q", plaintext)
			}
		})
	}
}

func TestDecryptStringPreviousKeysGolden(t *testing.T) {
	fixture := loadCredentialsFixture(t)
	rotation := fixture.Rotation

	_, err := credentials.DecryptString(rotation.Ciphertext, fixture.Metadata.TestKey)
	if err == nil {
		t.Fatal("current key alone decrypted a previous-key payload")
	}

	if err.Error() != rotation.DecryptWithCurrentOnly.Message {
		t.Fatalf("error = %q, want %q", err.Error(), rotation.DecryptWithCurrentOnly.Message)
	}

	plaintext, err := credentials.DecryptString(
		rotation.Ciphertext,
		fixture.Metadata.TestKey,
		fixture.Metadata.PrevKey,
	)
	if err != nil {
		t.Fatalf("decrypt with previous keys: %v", err)
	}

	if plaintext != rotation.DecryptWithBothKeys {
		t.Fatalf("plaintext = %q, want %q", plaintext, rotation.DecryptWithBothKeys)
	}
}

func TestRedactSecrets(t *testing.T) {
	fixture := loadCredentialsFixture(t)

	if _, err := credentials.DecryptString(fixture.Rotation.Ciphertext, fixture.Metadata.TestKey); err == nil {
		t.Fatal("expected MAC failure")
	}

	message := credentials.SafeError(
		credentials.ErrMACInvalid,
		fixture.Metadata.TestKey,
		fixture.Metadata.PrevKey,
		fixture.Rotation.Plaintext,
	)

	if strings.Contains(message, fixture.Metadata.TestKey) || strings.Contains(message, fixture.Rotation.Plaintext) {
		t.Fatalf("redacted message leaked a secret: %q", message)
	}

	redacted := credentials.Redact(
		"plaintext="+fixture.Rotation.Plaintext+" key="+fixture.Metadata.TestKey,
		fixture.Rotation.Plaintext,
		fixture.Metadata.TestKey,
	)
	want := "plaintext=[REDACTED] key=[REDACTED]"
	if redacted != want {
		t.Fatalf("redacted = %q, want %q", redacted, want)
	}
}

func TestKeyBytesRejectsShortKeys(t *testing.T) {
	if _, err := credentials.KeyBytes("base64:c2hvcnQ="); err == nil {
		t.Fatal("short key was accepted")
	}

	key, err := credentials.KeyBytes("base64:Z2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2dnZ2c=")
	if err != nil {
		t.Fatalf("decode key: %v", err)
	}

	want := []byte(strings.Repeat("g", 32))
	if !reflect.DeepEqual(key, want) {
		t.Fatalf("key bytes = %x, want 32 g bytes", key)
	}
}

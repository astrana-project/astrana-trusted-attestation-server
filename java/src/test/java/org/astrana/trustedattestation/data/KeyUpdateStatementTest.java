package org.astrana.trustedattestation.data;

import static org.assertj.core.api.Assertions.assertThat;

import jakarta.persistence.EntityManager;
import jakarta.persistence.EntityManagerFactory;
import java.lang.reflect.Method;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import org.hibernate.cfg.AvailableSettings;
import org.hibernate.cfg.Configuration;
import org.hibernate.resource.jdbc.spi.StatementInspector;
import org.junit.jupiter.api.Test;
import org.springframework.data.jpa.repository.Modifying;
import org.springframework.data.jpa.repository.Query;

/**
 * The SQL the key update executes names {@code public_key} and nothing else.
 *
 * <p>The documented application role holds UPDATE on that one column (decision record 16 in docs/adr), and every
 * engine refuses a statement that sets any other, so a return to saving the whole entity would break every
 * key write in production while passing every test that mocks the repository. This runs the repository's
 * own query through Hibernate against an in-memory database and reads back the statement it sent.
 */
class KeyUpdateStatementTest {

    /** Records every statement Hibernate hands the driver. Static because Hibernate instantiates it. */
    public static final class RecordingInspector implements StatementInspector {

        static final List<String> STATEMENTS = new ArrayList<>();

        @Override
        public String inspect(String sql) {
            STATEMENTS.add(sql);
            return sql;
        }
    }

    @Test
    void theKeyUpdateSetsPublicKeyAndNoOtherColumn() throws Exception {
        Method update = MemberRelationshipRepository.class.getMethod("updatePublicKey", Long.class, byte[].class);
        assertThat(update.getAnnotation(Modifying.class)).isNotNull();
        String jpql = update.getAnnotation(Query.class).value();

        EntityManagerFactory factory = new Configuration()
                .addAnnotatedClass(MemberRelationship.class)
                .setProperty(AvailableSettings.JAKARTA_JDBC_URL, "jdbc:h2:mem:key-update;DB_CLOSE_DELAY=-1")
                .setProperty(AvailableSettings.HBM2DDL_AUTO, "create-drop")
                .setProperty(AvailableSettings.STATEMENT_INSPECTOR, RecordingInspector.class.getName())
                .buildSessionFactory();

        try (factory;
                EntityManager entityManager = factory.createEntityManager()) {
            entityManager.getTransaction().begin();
            MemberRelationship row = new MemberRelationship("alice", "employee");
            entityManager.persist(row);
            entityManager.flush();
            RecordingInspector.STATEMENTS.clear();

            int updated = entityManager
                    .createQuery(jpql)
                    .setParameter("id", row.getId())
                    .setParameter("publicKey", new byte[32])
                    .executeUpdate();
            entityManager.getTransaction().commit();

            assertThat(updated).isEqualTo(1);
            List<String> updates = RecordingInspector.STATEMENTS.stream()
                    .map(sql -> sql.toLowerCase(Locale.ROOT))
                    .filter(sql -> sql.startsWith("update"))
                    .toList();
            assertThat(updates).hasSize(1);
            assertThat(updates.getFirst())
                    .matches(
                            "update member_relationships(?: \\w+)? set public_key ?= ?\\? where (?:\\w+\\.)?id ?= ?\\?");
            for (String otherColumn : List.of(
                    "iam_subject_id", "relationship_type", "relationship_subtype", "expires_at", "revoked_at")) {
                assertThat(updates.getFirst()).doesNotContain(otherColumn);
            }
        }
    }
}

<?php
// AJAX chart data for the dashboard and Researcher analytics.
// Like the PetStore dashboard_data.php: SQL returns counts, PHP converts
// them into JSON, and JavaScript draws the charts.
require_once __DIR__ . '/../app/bootstrap.php';
require_professional();
require_get();

try {
    $scope = (string)($_GET['scope'] ?? 'overview');
    $allowedScopes = ['overview', 'analytics', 'compare'];

    if (!in_array($scope, $allowedScopes, true)) {
        json_response(['error' => 'Unknown chart'], 422);
    }

    // Researchers can use the extra analytics views;
    // oncologists can still see the standard dashboard.
    if (($scope === 'analytics' || $scope === 'compare') && !is_researcher()) {
        json_response(['error' => 'Researcher access required'], 403);
    }

    // Counts are grouped by cancer cohort (LAML, BRCA, PAAD etc.).
    // LEFT JOIN ensures a cancer type is still listed when it has no patients.
    $cohortSql = 'SELECT c.CancerName, c.StudyCode,
                        COUNT(DISTINCT p.PatientID) AS Patients,
                        COUNT(DISTINCT s.SampleID) AS Samples,
                        COUNT(sm.SampleMutationID) AS Mutations
                  FROM CancerType c
                  LEFT JOIN Patient p ON p.CancerTypeID = c.CancerTypeID
                  LEFT JOIN TumorSample s ON s.PatientID = p.PatientID
                  LEFT JOIN SampleMutation sm ON sm.SampleID = s.SampleID
                  GROUP BY c.CancerTypeID, c.CancerName, c.StudyCode
                  ORDER BY c.CancerTypeID';
    $cohorts = query_rows($cohortSql);

    // Compare one gene across the six cancer types.
    if ($scope === 'compare') {
        $gene = trim((string)($_GET['gene'] ?? ''));
        if ($gene === '' || strlen($gene) > 100) {
            json_response(['error' => 'Enter a valid gene symbol'], 422);
        }

        $sql = 'SELECT c.CancerName, c.StudyCode,
                       COUNT(sm.SampleMutationID) AS Mutations,
                       COUNT(DISTINCT CASE
                         WHEN sm.SampleMutationID IS NOT NULL THEN s.SampleID
                       END) AS Samples
                FROM CancerType c
                LEFT JOIN Patient p ON p.CancerTypeID = c.CancerTypeID
                LEFT JOIN TumorSample s ON s.PatientID = p.PatientID
                LEFT JOIN SampleMutation sm
                       ON sm.SampleID = s.SampleID
                      AND sm.GeneID IN
                          (SELECT GeneID FROM Gene WHERE GeneSymbol = ?)
                GROUP BY c.CancerTypeID, c.CancerName, c.StudyCode
                ORDER BY c.CancerTypeID';

        $rows = query_rows($sql, [$gene]);
        json_response(['gene' => $gene, 'rows' => $rows]);
    }

    // Overview charts: cohort sizes and eight most-observed gene symbols.
    if ($scope === 'overview') {
        $sql = 'SELECT g.GeneSymbol, COUNT(sm.SampleMutationID) AS Mutations
                FROM Gene g
                INNER JOIN SampleMutation sm ON sm.GeneID = g.GeneID
                GROUP BY g.GeneID, g.GeneSymbol
                ORDER BY Mutations DESC, g.GeneSymbol
                LIMIT 8';
        $genes = query_rows($sql);
        json_response(['cohorts' => $cohorts, 'topGenes' => $genes]);
    }

    // Researcher analytics chart: top mutation classifications.
    $sql = 'SELECT MutationType, COUNT(*) AS Mutations
            FROM SampleMutation
            GROUP BY MutationType
            ORDER BY Mutations DESC
            LIMIT 8';
    $classes = query_rows($sql);
    json_response(['cohorts' => $cohorts, 'classes' => $classes]);

} catch (Throwable $error) {
    error_log('Stats API: ' . $error->getMessage());
    json_response(['error' => 'Unable to load chart data'], 500);
}
